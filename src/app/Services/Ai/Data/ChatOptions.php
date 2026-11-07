<?php

namespace App\Services\Ai\Data;

use App\Enums\ReasoningEffort;

final readonly class ChatOptions
{
    public function __construct(
        public ?int $maxTokens = null,
        public ?ReasoningEffort $reasoningEffort = null,
        // null — по умолчанию модели; уходит только моделям, которые его принимают
        public ?float $temperature = null,
        public bool $webSearch = false,
        // Не больше стольких поисков за ответ (там, где модель это соблюдает)
        public ?int $maxWebSearches = null,
        /** @var list<string> Искать только на этих сайтах (там, где поддерживается) */
        public array $allowedDomains = [],
    ) {}
}
