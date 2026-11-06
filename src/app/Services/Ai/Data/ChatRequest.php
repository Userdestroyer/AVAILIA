<?php

namespace App\Services\Ai\Data;

/**
 * Готовый HTTP-запрос к провайдеру. Стримовый выполняет Go-гейтвей, обычный — сам Laravel.
 */
final readonly class ChatRequest
{
    // Форматы API: от них зависит, как гейтвей разбирает стрим и как читается usage
    public const FORMAT_CHAT = 'chat';

    public const FORMAT_RESPONSES = 'responses';

    public const FORMAT_MESSAGES = 'messages';

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public string $url,
        public array $headers,
        public array $body,
        public string $format = self::FORMAT_CHAT,
        // Путь от базового адреса провайдера — по нему гейтвей строит URL сам
        public string $path = '/chat/completions',
    ) {}
}
