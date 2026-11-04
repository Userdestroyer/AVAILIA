<?php

namespace App\Services\Ai\Data;

/**
 * Готовый HTTP-запрос к провайдеру. Стримовый выполняет Go-гейтвей, обычный — сам Laravel.
 */
final readonly class ChatRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public string $url,
        public array $headers,
        public array $body,
    ) {}
}
