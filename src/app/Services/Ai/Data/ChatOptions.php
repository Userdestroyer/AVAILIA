<?php

namespace App\Services\Ai\Data;

use App\Enums\ReasoningEffort;

final readonly class ChatOptions
{
    public function __construct(
        public ?int $maxTokens = null,
        public ?ReasoningEffort $reasoningEffort = null,
        public bool $webSearch = false,
    ) {}
}
