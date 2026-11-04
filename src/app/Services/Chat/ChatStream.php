<?php

namespace App\Services\Chat;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\Data\ChatOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Жизненный цикл ответа: Laravel готовит запрос и кладёт его в Redis под одноразовым токеном,
 * Go-гейтвей стримит ответ и по завершении отчитывается сюда же — тут считается стоимость.
 * Мульти-запрос: на один вопрос — несколько моделей, у каждой свой ответ, стрим и токен.
 */
class ChatStream
{
    public function __construct(private AiProvider $provider) {}

    /**
     * @param  Collection<int, AiModel>  $models
     * @return array{chat: Chat, userMessage: Message, answers: list<array{message: Message, token: string}>}
     */
    public function start(User $user, ?Chat $chat, Collection $models, string $content, ChatOptions $options): array
    {
        [$chat, $userMessage, $answers] = DB::transaction(function () use ($user, $chat, $models, $content) {
            $primary = $models->first();

            if ($chat) {
                $chat->update(['ai_model_id' => $primary->id]);
                $chat->touch();
            } else {
                $chat = $user->chats()->create(['ai_model_id' => $primary->id, 'title' => Str::limit($content, 60)]);
            }

            $userMessage = $chat->messages()->create(['role' => MessageRole::User, 'content' => $content]);

            $answers = $models->map(fn (AiModel $model) => $chat->messages()->create([
                'parent_id' => $userMessage->id,
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Pending,
                'ai_model_id' => $model->id,
            ]));

            return [$chat, $userMessage, $answers];
        });

        $conversation = $chat->messages()->where('id', '<=', $userMessage->id)->orderBy('id')->get();
        $redis = Redis::connection('gateway');

        $started = $answers->map(function (Message $answer) use ($models, $conversation, $options, $redis) {
            $model = $models->firstWhere('id', $answer->ai_model_id)->loadMissing('vendor');
            $request = $this->provider->chatRequest($model, $this->historyFor($conversation, $model), $options, stream: true);

            // Ключ провайдера сюда не кладём — он есть у гейтвея; кладём только тело запроса
            $token = Str::random(48);
            $redis->setex("stream:{$token}", config('ai.gateway.stream_ttl'), json_encode([
                'provider' => $this->provider->name(),
                'message_id' => $answer->id,
                'body' => $request->body,
            ]));

            return ['message' => $answer, 'token' => $token];
        });

        return ['chat' => $chat, 'userMessage' => $userMessage, 'answers' => $started->values()->all()];
    }

    /**
     * История для конкретной модели: вопросы и по одному удавшемуся ответу на каждый —
     * её собственный, а если в том ходе её не было — первый. Иначе модель увидела бы подряд
     * несколько «своих» ответов от разных моделей.
     *
     * @param  Collection<int, Message>  $conversation
     * @return Collection<int, Message>
     */
    private function historyFor(Collection $conversation, AiModel $model): Collection
    {
        $answers = $conversation
            ->where('role', MessageRole::Assistant)
            ->where('status', MessageStatus::Done)
            ->groupBy('parent_id');

        return $conversation
            ->where('role', MessageRole::User)
            ->flatMap(function (Message $question) use ($answers, $model) {
                $turn = $answers->get($question->id);
                $answer = $turn?->firstWhere('ai_model_id', $model->id) ?? $turn?->first();

                return array_filter([$question, $answer]);
            })
            ->values();
    }

    /**
     * Гейтвей сообщил итог стрима: сохраняем текст, токены и себестоимость.
     *
     * @param  array{status: string, content?: ?string, usage?: ?array, error?: ?string}  $result
     */
    public function complete(Message $message, array $result): Message
    {
        // Повторный отчёт по уже закрытому ответу ничего не меняет
        if (in_array($message->status, [MessageStatus::Done, MessageStatus::Failed], true)) {
            return $message;
        }

        if ($result['status'] === MessageStatus::Failed->value) {
            $message->update([
                'status' => MessageStatus::Failed,
                'content' => $result['content'] ?? null,
                'error' => $result['error'] ?? 'Модель не ответила',
            ]);

            return $message;
        }

        $usage = $this->provider->parseUsage($result['usage'] ?? []);
        $model = $message->aiModel;

        $message->update([
            'status' => MessageStatus::Done,
            'content' => $result['content'] ?? '',
            'input_tokens' => $usage->input,
            'output_tokens' => $usage->output,
            'cache_read_tokens' => $usage->cacheRead,
            'cache_write_tokens' => $usage->cacheWrite,
            'cost' => $model?->calculateCost($usage->input, $usage->output, $usage->cacheRead, $usage->cacheWrite) ?? '0',
            'cost_currency' => $model?->price_currency ?? 'USD',
        ]);

        $message->chat->touch();

        return $message;
    }
}
