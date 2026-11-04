<?php

namespace App\Services\Ai\Data;

/**
 * Токены, разложенные по ценам: input — только некэшированный ввод.
 */
final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $cacheWrite = 0,
    ) {}
}
