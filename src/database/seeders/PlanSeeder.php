<?php

namespace Database\Seeders;

use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Лимиты — стартовые значения, правятся в БД. null — без ограничений.
     */
    public function run(): void
    {
        $plans = [
            [
                'code' => PlanCode::Guest->value,
                'name' => 'Гость',
                'daily_request_limit' => 20,
                'monthly_token_limit' => 200_000,
                'monthly_budget_usd' => 1,
            ],
            [
                'code' => PlanCode::Promo->value,
                'name' => 'Промо',
                'description' => 'Повышенные лимиты для своих, выдаётся вручную',
                'daily_request_limit' => 100,
                'monthly_token_limit' => 2_000_000,
                'monthly_budget_usd' => 5,
                'is_public' => false,
            ],
            [
                'code' => PlanCode::Pro->value,
                'name' => 'Pro',
                'daily_request_limit' => 500,
                'monthly_token_limit' => 10_000_000,
                'monthly_budget_usd' => 20,
            ],
            [
                'code' => PlanCode::Unlimited->value,
                'name' => 'Unlimited',
                'is_public' => false,
            ],
        ];

        foreach ($plans as $sort => $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan + ['sort' => $sort]);
        }
    }
}
