<?php

namespace App\Services\Ai\Data;

final readonly class Completion
{
    public function __construct(
        public string $content,
        public Usage $usage,
    ) {}
}
