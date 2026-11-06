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
     * Как у этого агрегатора искать в интернете моделью: формат API и какие параметры
     * он реально соблюдает. null — поиск недоступен, запрос пойдёт без него.
     *
     * @return array{format: string, max_uses: bool, domains: bool}|null
     */
    public function webSearch(AiModel $model): ?array
    {
        return null;
    }

    /**
     * Цена одного поиска в валюте цен модели.
     */
    public function webSearchPrice(AiModel $model): string
    {
        return '0';
    }

    /**
     * Стримовый запрос для чата: с включённым поиском — в формате, где поиск есть,
     * иначе обычный chat/completions.
     *
     * @param  iterable<Message>  $messages
     */
    public function request(AiModel $model, iterable $messages, ChatOptions $options, bool $stream = true): ChatRequest
    {
        $search = $options->webSearch ? $this->webSearch($model) : null;

        return match ($search['format'] ?? null) {
            ChatRequest::FORMAT_MESSAGES => $this->messagesRequest($model, $messages, $options, $search, $stream),
            ChatRequest::FORMAT_RESPONSES => $this->responsesRequest($model, $messages, $options, $search, $stream),
            default => $this->chatRequest($model, $messages, $options, $stream),
        };
    }

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
     * OpenAI Responses: единый формат, где у агрегатора есть поиск в интернете.
     *
     * @param  iterable<Message>  $messages
     * @param  array{format: string, max_uses: bool, domains: bool}  $search
     */
    protected function responsesRequest(AiModel $model, iterable $messages, ChatOptions $options, array $search, bool $stream): ChatRequest
    {
        $tool = ['type' => 'web_search'];
        if ($search['max_uses'] && $options->maxWebSearches) {
            $tool['max_uses'] = $options->maxWebSearches;
        }
        if ($search['domains'] && $options->allowedDomains) {
            $tool['filters'] = ['allowed_domains' => $options->allowedDomains];
        }

        $body = array_filter([
            'model' => $this->modelId($model),
            'input' => $this->formatMessages($messages),
            'max_output_tokens' => $options->maxTokens,
            'reasoning' => $options->reasoningEffort ? ['effort' => $options->reasoningEffort->value] : null,
            'stream' => $stream ?: null,
            'tools' => [$tool],
        ], fn ($value) => $value !== null);

        return new ChatRequest(
            url: rtrim($this->baseUrl, '/').'/responses',
            headers: $this->headers(),
            body: $body,
            format: ChatRequest::FORMAT_RESPONSES,
            path: '/responses',
        );
    }

    /**
     * Anthropic Messages: только тут Claude соблюдает лимит поисков и список доменов.
     *
     * @param  iterable<Message>  $messages
     * @param  array{format: string, max_uses: bool, domains: bool}  $search
     */
    protected function messagesRequest(AiModel $model, iterable $messages, ChatOptions $options, array $search, bool $stream): ChatRequest
    {
        $tool = ['type' => 'web_search_20250305', 'name' => 'web_search'];
        if ($search['max_uses'] && $options->maxWebSearches) {
            $tool['max_uses'] = $options->maxWebSearches;
        }
        if ($search['domains'] && $options->allowedDomains) {
            $tool['allowed_domains'] = $options->allowedDomains;
        }

        $body = array_filter([
            'model' => $this->modelId($model),
            // В Messages лимит ответа обязателен
            'max_tokens' => $options->maxTokens ?? 4096,
            'messages' => $this->formatMessages($messages),
            'stream' => $stream ?: null,
            'tools' => [$tool],
        ], fn ($value) => $value !== null);

        return new ChatRequest(
            url: rtrim($this->baseUrl, '/').'/messages',
            headers: [...$this->headers(), 'anthropic-version' => '2023-06-01'],
            body: $body,
            format: ChatRequest::FORMAT_MESSAGES,
            path: '/messages',
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
     * usage в формате API → токены по ценам. В OpenAI-форматах (chat, responses) ввод уже
     * включает кэшированные токены — вычитаем их, чтобы каждый токен посчитался по одной цене;
     * в Anthropic Messages кэш считается отдельно от ввода.
     *
     * @param  array<string, mixed>  $usage
     */
    public function parseUsage(array $usage, string $format = ChatRequest::FORMAT_CHAT): Usage
    {
        if ($format === ChatRequest::FORMAT_MESSAGES) {
            return new Usage(
                input: (int) ($usage['input_tokens'] ?? 0),
                output: (int) ($usage['output_tokens'] ?? 0),
                cacheRead: (int) ($usage['cache_read_input_tokens'] ?? 0),
                cacheWrite: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            );
        }

        [$prompt, $details, $completion] = $format === ChatRequest::FORMAT_RESPONSES
            ? [$usage['input_tokens'] ?? 0, $usage['input_tokens_details'] ?? [], $usage['output_tokens'] ?? 0]
            : [$usage['prompt_tokens'] ?? 0, $usage['prompt_tokens_details'] ?? [], $usage['completion_tokens'] ?? 0];

        $cacheRead = (int) ($details['cached_tokens'] ?? 0);
        $cacheWrite = (int) ($details['cache_write_tokens'] ?? 0);

        return new Usage(
            input: max(0, (int) $prompt - $cacheRead - $cacheWrite),
            output: (int) $completion,
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
