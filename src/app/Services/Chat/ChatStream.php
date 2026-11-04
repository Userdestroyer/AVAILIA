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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Жизненный цикл ответа: Laravel готовит запрос и кладёт его в Redis под одноразовым токеном,
 * Go-гейтвей стримит ответ и по завершении отчитывается сюда же — тут считается стоимость.
 */
class ChatStream
{
    public function __construct(private AiProvider $provider) {}

    /**
     * @return array{chat: Chat, userMessage: Message, assistantMessage: Message, token: string}
     */
    public function start(User $user, ?Chat $chat, AiModel $model, string $content, ChatOptions $options): array
    {
        [$chat, $userMessage, $assistantMessage] = DB::transaction(function () use ($user, $chat, $model, $content) {
            if ($chat) {
                $chat->update(['ai_model_id' => $model->id]);
                $chat->touch();
            } else {
                $chat = $user->chats()->create(['ai_model_id' => $model->id, 'title' => Str::limit($content, 60)]);
            }

            return [
                $chat,
                $chat->messages()->create(['role' => MessageRole::User, 'content' => $content]),
                $chat->messages()->create([
                    'role' => MessageRole::Assistant,
                    'status' => MessageStatus::Pending,
                    'ai_model_id' => $model->id,
                ]),
            ];
        });

        // В историю идут сообщения юзера и удавшиеся ответы — без упавших и недописанных
        $history = $chat->messages()
            ->where('id', '<', $assistantMessage->id)
            ->where(fn ($query) => $query
                ->where('role', MessageRole::User)
                ->orWhere('status', MessageStatus::Done))
            ->orderBy('id')
            ->get();

        $request = $this->provider->chatRequest($model->loadMissing('vendor'), $history, $options, stream: true);

        // Ключ провайдера сюда не кладём — он есть у гейтвея; кладём только тело запроса
        $token = Str::random(48);
        Redis::connection('gateway')->setex("stream:{$token}", config('ai.gateway.stream_ttl'), json_encode([
            'provider' => $this->provider->name(),
            'message_id' => $assistantMessage->id,
            'body' => $request->body,
        ]));

        return compact('chat', 'userMessage', 'assistantMessage', 'token');
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
