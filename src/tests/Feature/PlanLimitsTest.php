<?php

namespace Tests\Feature;

use App\Enums\UsageScope;
use App\Models\Plan;
use App\Models\UsageWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    public static function windows(): array
    {
        return [
            // 2500 / 4,345 = 575,37 в неделю; ÷ 4,5 сессии = 127,86
            'текст, 4,5 сессии в неделю' => [['text_monthly' => 2500, 'sessions_per_week' => 4.5], UsageScope::Text, [575.37, 127.86]],
            // Меньше сессий — каждая заметнее
            'агенты, 1,5 сессии' => [['agent_monthly' => 304.15, 'sessions_per_week' => 1.5], UsageScope::Agent, [70.0, 46.67]],
            'пусто — без ограничений' => [['text_monthly' => null], UsageScope::Text, [null, null]],
            'у контента денежных окон нет' => [['text_monthly' => 100], UsageScope::Content, [null, null]],
        ];
    }

    #[Group('A1')]
    #[DataProvider('windows')]
    #[TestDox('Лимиты окон из тарифа: неделя = месяц ÷ 4,345, сессия = неделя ÷ сессий в неделю')]
    public function test_window_limits_follow_plan(array $plan, UsageScope $scope, array $expected): void
    {
        $plan = new Plan(['sessions_per_week' => 4.5, ...$plan]);
        $round = fn (?float $v) => $v === null ? null : round($v, 2);

        $this->assertSame($expected, [$round($plan->windowLimit($scope, UsageWindow::WEEK)), $round($plan->windowLimit($scope, UsageWindow::SESSION))]);
    }
}
