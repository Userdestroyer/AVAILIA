<?php

namespace Database\Seeders;

use App\Enums\PlanCode;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Стартовые лимиты (docs/LIMITS.md): ₽ в месяц на текст и агентов, токенов в месяц на контент.
     * Только создаёт недостающие — откалиброванное в админке не затирается.
     */
    public function run(): void
    {
        $plans = [
            [
                'code' => PlanCode::Promo->value,
                'name' => 'Promo',
                'description' => 'По умолчанию для всех',
                'text_monthly' => 100,
                'agent_monthly' => 100,
                'content_tokens_monthly' => 20,
                // Сессия заметная: неделю можно выжечь за пару сессий
                'sessions_per_week' => 2,
                'is_public' => false,
            ],
            [
                'code' => PlanCode::Custom->value,
                'name' => 'Custom',
                'description' => 'Калибровка лимитов — правки не задевают остальных',
                'text_monthly' => 2500,
                'agent_monthly' => 1000,
                'content_tokens_monthly' => 500,
                'sessions_per_week' => 4.5,
                'is_public' => false,
            ],
        ];

        foreach ($plans as $sort => $plan) {
            Plan::firstOrCreate(['code' => $plan['code']], $plan + ['sort' => $sort]);
        }
    }
}
