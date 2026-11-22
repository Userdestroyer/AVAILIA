<?php

namespace App\Services\Chat;

use App\Enums\MessageRole;
use App\Jobs\GenerateChatTitle;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Enums\UsageScope;
use App\Models\Chat;
use App\Models\ContextPlate;
use App\Models\Message;
use App\Models\ProviderModel;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Ai\Data\ChatRequest;
use App\Services\Ai\Data\Usage;
use App\Services\Ai\ProviderRegistry;
use App\Services\Limits\Money;
use App\Services\Limits\UsageMeter;
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
    public function __construct(
        private ProviderRegistry $providers,
        private UsageMeter $meter,
    ) {}

    /**
     * @param  Collection<int, ProviderModel>  $offers  предложения моделей у провайдера, в порядке выбора юзера
     * @param  Collection<int, ContextPlate>  $plates  приложенные к вопросу картриджи — уже только свои (отобраны по владельцу)
     * @param  Collection<int, UserFile>  $files  приложенные файлы — уже только свои, в порядке прикрепления
     * @return array{chat: Chat, userMessage: Message, answers: list<array{message: Message, token: string}>}
     */
    public function start(User $user, ?Chat $chat, Collection $offers, string $content, ChatOptions $options, ?Collection $plates = null, ?Collection $files = null): array
    {
        $isNew = $chat === null;
        $plates ??= collect();
        $files ??= collect();
        // Вопрос без подписи (только файлы) — назовём чат по ним
        $topic = $content !== '' ? $content : $files->pluck('name')->join(', ');

        // Картридж прикладывается к вопросу в текущей версии; уже приложенный выше в той же версии — не повторяем
        $attached = $plates->map(fn (ContextPlate $plate) => ['plate_id' => $plate->id, 'version_id' => $plate->current_version_id, 'title' => $plate->title]);
        if ($chat && $attached->isNotEmpty()) {
            $earlier = $chat->messages()->where('role', MessageRole::User)->whereNotNull('context')->get(['context'])
                ->flatMap(fn (Message $question) => $question->context)
                ->map(fn (array $entry) => $entry['plate_id'].':'.($entry['version_id'] ?? ''))
                ->flip();
            $attached = $attached->reject(fn (array $entry) => $earlier->has($entry['plate_id'].':'.$entry['version_id']));
        }

        [$chat, $userMessage, $answers] = DB::transaction(function () use ($user, $chat, $offers, $content, $attached, $files, $topic) {
            $primary = $offers->first()->aiModel;

            if ($chat) {
                $chat->update(['ai_model_id' => $primary->id]);
                $chat->touch();
            } else {
                $chat = $user->chats()->create(['ai_model_id' => $primary->id, 'title' => GenerateChatTitle::fallback($topic)]);
            }

            $userMessage = $chat->messages()->create([
                'role' => MessageRole::User,
                'content' => $content,
                // Какие картриджи (в каких версиях) приложены к этому вопросу
                'context' => $attached->isEmpty() ? null : $attached->values()->all(),
            ]);

            if ($files->isNotEmpty()) {
                $userMessage->files()->attach($files->values()->mapWithKeys(fn (UserFile $file, int $sort) => [$file->id => ['sort' => $sort]])->all());
            }
            $userMessage->setRelation('files', $files->values());

            $answers = $offers->map(fn (ProviderModel $offer) => $chat->messages()->create([
                'parent_id' => $userMessage->id,
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Pending,
                'ai_model_id' => $offer->ai_model_id,
                'provider_id' => $offer->provider_id,
            ]));

            return [$chat, $userMessage, $answers];
        });

        // Название — фоном, параллельно ответу: постановка в очередь — это одна запись в Redis
        if ($isNew) {
            GenerateChatTitle::dispatch($chat->id, $topic);
        }

        [$system, $conversation] = ContextPrompt::inline($user, $chat->messages()->where('id', '<=', $userMessage->id)->with('files')->orderBy('id')->get());
        $started = $answers->map(fn (Message $answer) => [
            'message' => $answer,
            'token' => $this->dispatch($answer, $offers->firstWhere('ai_model_id', $answer->ai_model_id), $system, $conversation, $options),
        ]);

        return ['chat' => $chat, 'userMessage' => $userMessage, 'answers' => $started->values()->all()];
    }

    /**
     * Ещё один вариант ответа той же модели на тот же вопрос — с теми же картриджами в тех же версиях
     * (они записаны у вопросов). Новый вариант становится текущим: его видно в чате и он идёт в историю дальше.
     *
     * @return array{message: Message, token: string}
     */
    public function regenerate(Message $answer, ProviderModel $offer, ChatOptions $options): array
    {
        $chat = $answer->chat;

        $variant = DB::transaction(function () use ($chat, $answer, $offer) {
            $chat->messages()->where('parent_id', $answer->parent_id)->where('ai_model_id', $answer->ai_model_id)->update(['is_current' => false]);
            $chat->touch();

            return $chat->messages()->create([
                'parent_id' => $answer->parent_id,
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Pending,
                'ai_model_id' => $offer->ai_model_id,
                'provider_id' => $offer->provider_id,
                'is_current' => true,
            ]);
        });

        // Разговор до этого вопроса: прежние вопросы с их ответами (в т.ч. перегенерированными позже), без ответов на него
        $conversation = $chat->messages()->with('files')->orderBy('id')->get()->filter(fn (Message $message) => $message->role === MessageRole::User
            ? $message->id <= $answer->parent_id
            : $message->parent_id < $answer->parent_id);

        [$system, $conversation] = ContextPrompt::inline($chat->user, $conversation->values());

        return ['message' => $variant, 'token' => $this->dispatch($variant, $offer, $system, $conversation, $options)];
    }

    /**
     * Стрим-задание для гейтвея: тело запроса — в Redis под одноразовым токеном.
     *
     * @param  ?Message  $system  неподвижная инструкция о картриджах (если они есть в разговоре)
     * @param  Collection<int, Message>  $conversation  картриджи — уже в сообщениях, к которым приложены
     */
    private function dispatch(Message $answer, ProviderModel $offer, ?Message $system, Collection $conversation, ChatOptions $options): string
    {
        $preamble = $system ? collect([$system]) : collect();
        $provider = $this->providers->provider($offer->provider->slug);
        $request = $provider->request($offer, $preamble->concat($this->historyFor($conversation, $offer->aiModel)), $options);

        // Ключ провайдера сюда не кладём — он есть у гейтвея; кладём только тело запроса
        $token = Str::random(48);
        Redis::connection('gateway')->setex("stream:{$token}", config('ai.gateway.stream_ttl'), json_encode([
            'provider' => $provider->name(),
            'message_id' => $answer->id,
            // Формат определяет, как гейтвей разбирает стрим; путь — куда слать (база — у гейтвея)
            'format' => $request->format,
            'path' => $request->path,
            'body' => $request->body,
        ]));

        return $token;
    }

    /**
     * История для конкретной модели: вопросы и по одному удавшемуся ответу на каждый —
     * её собственный (выбранный вариант, если их несколько), а если в том ходе её не было — первый. Иначе модель увидела бы подряд
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
                // Выбранные варианты — первыми (сортировка устойчивая: дальше по порядку)
                $turn = $answers->get($question->id)?->sortByDesc('is_current');
                $answer = $turn?->firstWhere('ai_model_id', $model->id) ?? $turn?->first();

                return array_filter([$question, $answer]);
            })
            ->values();
    }

    /**
     * Гейтвей сообщил итог стрима: сохраняем текст, токены и себестоимость.
     *
     * @param  array{status: string, format?: ?string, content?: ?string, usage?: ?array, web_searches?: ?int, searches?: ?array, error?: ?string, ttft_ms?: ?int, duration_ms?: ?int}  $result
     */
    public function complete(Message $message, array $result): Message
    {
        // Повторный отчёт по уже закрытому ответу ничего не меняет
        if (in_array($message->status, [MessageStatus::Done, MessageStatus::Failed], true)) {
            return $message;
        }

        $speed = ['ttft_ms' => $result['ttft_ms'] ?? null, 'duration_ms' => $result['duration_ms'] ?? null];

        if ($result['status'] === MessageStatus::Failed->value) {
            $message->update([
                ...$speed,
                'status' => MessageStatus::Failed,
                'content' => $result['content'] ?? null,
                'error' => $result['error'] ?? 'Модель не ответила',
                'search_log' => $result['searches'] ?? null,
            ]);

            return $message;
        }

        $provider = $this->providers->provider($message->provider?->slug ?? $this->providers->activeSlug());
        $offer = ProviderModel::where('provider_id', $message->provider_id)->where('ai_model_id', $message->ai_model_id)->first();
        $rawUsage = $result['usage'] ?? [];
        $usage = $provider->parseUsage($rawUsage, $result['format'] ?? ChatRequest::FORMAT_CHAT);
        $searches = (int) ($result['web_searches'] ?? 0);

        // Себестоимость: что сообщил провайдер (точнее), иначе по расценкам; нет расценок — неизвестна
        $reported = $provider->reportedCost($rawUsage);
        $cost = $reported['amount']
            ?? $offer?->calculateCost($usage->input, $usage->output, $usage->cacheRead, $usage->cacheWrite, $searches);

        $message->update([
            ...$speed,
            'status' => MessageStatus::Done,
            'content' => $result['content'] ?? '',
            'input_tokens' => $usage->input,
            'output_tokens' => $usage->output,
            'cache_read_tokens' => $usage->cacheRead,
            'cache_write_tokens' => $usage->cacheWrite,
            'web_searches' => $searches,
            'search_log' => $result['searches'] ?? null,
            'cost' => $cost,
            'cost_currency' => $reported['currency'] ?? $offer?->price_currency ?? 'RUB',
        ]);

        // Чат могли удалить, пока ответ писался — ответ всё равно сохранён и учтён
        $message->chat?->touch();
        $this->charge($message, $offer, $usage, $searches);

        return $message;
    }

    /**
     * Трата ответа — в текстовый лимит владельца чата (и удалённого тоже: деньги потрачены).
     * Стоимость неизвестна — оценка по самой дорогой известной цене вендора: иначе модель без расценок
     * обходила бы лимит.
     */
    private function charge(Message $message, ?ProviderModel $offer, Usage $usage, int $searches): void
    {
        $user = Chat::withTrashed()->find($message->chat_id)?->user;
        if (! $user) {
            return;
        }

        if ($message->cost !== null) {
            $this->meter->charge($user, UsageScope::Text, Money::toRub($message->cost, $message->cost_currency, $message->created_at), $message);

            return;
        }

        $estimate = ProviderModel::estimateCost($offer, $usage, $searches);
        $this->meter->charge($user, UsageScope::Text, $estimate, $message, estimated: true);
    }
}
