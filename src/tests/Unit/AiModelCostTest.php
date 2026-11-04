<?php

namespace Tests\Unit;

use App\Models\AiModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class AiModelCostTest extends TestCase
{
    #[Group('U1')]
    #[TestDox('Стоимость запроса считается по ценам за 1M токенов; кэш без своей цены — по цене ввода')]
    public function test_cost_is_calculated_from_prices_per_million_tokens(): void
    {
        $model = new AiModel([
            'price_input' => '3',
            'price_output' => '15',
            'price_cache_read' => '0.3',
            'price_cache_write' => null,
        ]);

        // 1000 × 3 + 2000 × 15 + 10000 × 0.3 + 500 × 3 (запись в кэш без цены — как ввод) = 37500 → / 1M
        $this->assertSame('0.03750000', $model->calculateCost(1000, 2000, 10000, 500));
    }
}
