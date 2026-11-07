<?php

namespace App\Services\Ai;

use App\Models\ProviderModel;
use App\Services\Ai\Data\ChatRequest;

/**
 * proxyapi.ru — единый OpenAI-совместимый эндпоинт, модели в формате vendor/model.
 */
class ProxyApiProvider extends AiProvider
{
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

    public function webSearch(ProviderModel $offer): ?array
    {
        return self::WEB_SEARCH[$offer->aiModel->vendor->slug] ?? null;
    }
}
