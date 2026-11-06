<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\Ai\Data\ChatRequest;

/**
 * proxyapi.ru — единый OpenAI-совместимый эндпоинт, модели в формате vendor/model.
 */
class ProxyApiProvider extends AiProvider
{
    // Где префикс вендора у proxyapi отличается от нашего slug
    private const VENDOR_PREFIXES = [
        'xai' => 'x-ai',
    ];

    /**
     * Поиск в интернете у proxyapi (проверено запросами, 2026-11-07): в chat/completions его нет.
     * Claude — родной Messages: только там соблюдаются max_uses и allowed_domains
     * (через Responses proxyapi их принимает, но игнорирует). GPT и Gemini — Responses:
     * у GPT нет лимита поисков, но работает filters.allowed_domains; Gemini принимает max_uses,
     * доменов не поддерживает. У Grok поиска нет.
     */
    private const WEB_SEARCH = [
        'anthropic' => ['format' => ChatRequest::FORMAT_MESSAGES, 'max_uses' => true, 'domains' => true],
        'openai' => ['format' => ChatRequest::FORMAT_RESPONSES, 'max_uses' => false, 'domains' => true],
        'google' => ['format' => ChatRequest::FORMAT_RESPONSES, 'max_uses' => true, 'domains' => false],
    ];

    public function name(): string
    {
        return 'proxyapi';
    }

    public function webSearch(AiModel $model): ?array
    {
        return self::WEB_SEARCH[$model->vendor->slug] ?? null;
    }

    public function webSearchPrice(AiModel $model): string
    {
        return (string) (config('ai.providers.proxyapi.web_search_prices')[$model->vendor->slug] ?? 0);
    }

    public function modelId(AiModel $model): string
    {
        $vendor = $model->vendor->slug;

        return (self::VENDOR_PREFIXES[$vendor] ?? $vendor).'/'.$model->slug;
    }
}
