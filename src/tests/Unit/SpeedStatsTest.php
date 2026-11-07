<?php

namespace Tests\Unit;

use App\Services\Stats\SpeedStats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class SpeedStatsTest extends TestCase
{
    public static function percentiles(): array
    {
        return [
            'медиана нечётного набора' => [[300, 100, 200], 0.5, 200],
            'p90 по ближайшему рангу' => [[1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.9, 9],
            'пропуски не считаются' => [[null, 500, null], 0.5, 500],
            'пусто — неизвестно' => [[], 0.5, null],
        ];
    }

    #[Group('U2')]
    #[DataProvider('percentiles')]
    public function test_percentile(array $values, float $p, ?int $expected): void
    {
        $this->assertSame($expected, SpeedStats::percentile(collect($values), $p));
    }
}
