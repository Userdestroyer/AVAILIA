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
     * (через Responses proxyapi их принимает, но игнорирует). GPT и Gemini — Responses.
     * У Grok поиска нет. Что соблюдает каждая модель — в её возможности web_search (сидер).
     */
    private const SEARCH_FORMATS = [
        'anthropic' => ChatRequest::FORMAT_MESSAGES,
        'openai' => ChatRequest::FORMAT_RESPONSES,
        'google' => ChatRequest::FORMAT_RESPONSES,
    ];

    public function name(): string
    {
        return 'proxyapi';
    }

    protected function searchFormat(ProviderModel $offer): ?string
    {
        return self::SEARCH_FORMATS[$offer->aiModel->vendor->slug] ?? null;
    }
}
