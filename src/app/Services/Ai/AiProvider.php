<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\Message;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Ai\Data\ChatRequest;
use App\Services\Ai\Data\Completion;
use App\Services\Ai\Data\Usage;
use Illuminate\Support\Facades\Http;

/**
 * Агрегатор с OpenAI-совместимым API (chat/completions): proxyapi, Poiza и т.п.
 * Наследники задают то, чем агрегаторы отличаются: id модели, заголовки, доп. поля запроса.
 */
abstract class AiProvider
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $apiKey,
    ) {}

    abstract public function name(): string;

    /**
     * Идентификатор модели у этого агрегатора.
     */
    abstract public function modelId(AiModel $model): string;

    /**
     * @param  iterable<Message>  $messages
     */
    public function chatRequest(AiModel $model, iterable $messages, ChatOptions $options, bool $stream = false): ChatRequest
    {
        $body = array_filter([
            'model' => $this->modelId($model),
            'messages' => $this->formatMessages($messages),
            'max_tokens' => $options->maxTokens,
            'reasoning_effort' => $options->reasoningEffort?->value,
            'stream' => $stream ?: null,
            // Без этого OpenAI-совместимый стрим не отдаёт usage в последнем чанке
            'stream_options' => $stream ? ['include_usage' => true] : null,
        ], fn ($value) => $value !== null);

        return new ChatRequest(
            url: rtrim($this->baseUrl, '/').'/chat/completions',
            headers: $this->headers(),
            body: [...$body, ...$this->extraBody($model, $options)],
        );
    }

    /**
     * Обычный (не стримовый) запрос — для коротких задач из очередей: названия чатов и т.п.
     *
     * @param  iterable<Message>  $messages
     */
    public function complete(AiModel $model, iterable $messages, ChatOptions $options = new ChatOptions): Completion
    {
        $request = $this->chatRequest($model, $messages, $options);

        $data = Http::withHeaders($request->headers)
            ->timeout(120)
            ->post($request->url, $request->body)
            ->throw()
            ->json();

        return new Completion(
            content: $data['choices'][0]['message']['content'] ?? '',
            usage: $this->parseUsage($data['usage'] ?? []),
        );
    }

    /**
     * В OpenAI-формате prompt_tokens уже включает кэшированные токены — вычитаем их,
     * чтобы каждый токен посчитался ровно по одной цене.
     *
     * @param  array<string, mixed>  $usage
     */
    public function parseUsage(array $usage): Usage
    {
        $details = $usage['prompt_tokens_details'] ?? [];
        $cacheRead = (int) ($details['cached_tokens'] ?? 0);
        $cacheWrite = (int) ($details['cache_write_tokens'] ?? 0);

        return new Usage(
            input: max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cacheRead - $cacheWrite),
            output: (int) ($usage['completion_tokens'] ?? 0),
            cacheRead: $cacheRead,
            cacheWrite: $cacheWrite,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->apiKey];
    }

    /**
     * Поля запроса, специфичные для агрегатора (веб-поиск, роутинг и т.п.).
     *
     * @return array<string, mixed>
     */
    protected function extraBody(AiModel $model, ChatOptions $options): array
    {
        return [];
    }

    /**
     * @param  iterable<Message>  $messages
     * @return list<array{role: string, content: string}>
     */
    protected function formatMessages(iterable $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            $formatted[] = ['role' => $message->role->value, 'content' => (string) $message->content];
        }

        return $formatted;
    }
}
