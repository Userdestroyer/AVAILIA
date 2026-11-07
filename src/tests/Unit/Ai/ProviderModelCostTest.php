<?php

namespace Tests\Unit\Ai;

use App\Models\ProviderModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ProviderModelCostTest extends TestCase
{
    public static function costs(): array
    {
        return [
            // 1000 × 3 + 2000 × 15 + 10000 × 0.3 + 500 × 3 (запись в кэш без цены — как ввод) = 37500 → / 1M
            'кэш без своей цены — по цене ввода' => [['price_input' => 3, 'price_output' => 15, 'price_cache_read' => 0.3], 0, '0.03750000'],
            // + 2 поиска × 0,5
            'поиски по цене за поиск' => [['price_input' => 3, 'price_output' => 15, 'price_cache_read' => 0.3, 'price_web_search' => 0.5], 2, '1.03750000'],
            // Расценок нет — стоимость неизвестна, а не ноль
            'без расценок — неизвестна' => [['price_input' => null, 'price_output' => 15], 0, null],
        ];
    }

    #[Group('U1')]
    #[DataProvider('costs')]
    #[TestDox('Стоимость по расценкам за 1M токенов и поиск; без расценок — неизвестна, а не ноль')]
    public function test_cost_by_prices(array $prices, int $webSearches, ?string $expected): void
    {
        $this->assertSame($expected, (new ProviderModel($prices))->calculateCost(1000, 2000, 10000, 500, $webSearches));
    }
}
