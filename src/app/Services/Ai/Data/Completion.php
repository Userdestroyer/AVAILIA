<?php

namespace App\Services\Ai\Data;

final readonly class Completion
{
    public function __construct(
        public string $content,
        public Usage $usage,
        /** @var array<string, mixed> usage как прислал провайдер — в нём бывает готовая стоимость (Polza) */
        public array $rawUsage = [],
    ) {}
}
