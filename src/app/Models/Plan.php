<?php

namespace App\Models;

use App\Enums\PlanCode;
use App\Enums\UsageScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тариф: лимиты по сферам (docs/LIMITS.md). Деньги — ₽ в месяц, неделя и сессия выводятся из них.
 */
#[Fillable([
    'code', 'name', 'description', 'price_monthly', 'currency',
    'text_monthly', 'agent_monthly', 'content_tokens_monthly', 'sessions_per_week', 'session_hours',
    'is_public', 'sort',
])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'text_monthly' => 'decimal:2',
            'agent_monthly' => 'decimal:2',
            'content_tokens_monthly' => 'integer',
            'sessions_per_week' => 'decimal:2',
            'session_hours' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public static function byCode(PlanCode $code): self
    {
        return static::where('code', $code->value)->firstOrFail();
    }

    /**
     * Денежный лимит сферы в ₽ на окно: неделя = месяц ÷ 4,345, сессия = неделя ÷ сессий в неделю.
     * null — без ограничений (или у сферы нет денежных окон).
     */
    public function windowLimit(UsageScope $scope, string $kind): ?float
    {
        $monthly = match ($scope) {
            UsageScope::Text => $this->text_monthly,
            UsageScope::Agent => $this->agent_monthly,
            UsageScope::Content => null,
        };
        if ($monthly === null) {
            return null;
        }

        $week = (float) $monthly / config('limits.weeks_per_month');

        return $kind === UsageWindow::SESSION ? $week / max(0.1, (float) $this->sessions_per_week) : $week;
    }

    public function windowHours(string $kind): int
    {
        return $kind === UsageWindow::SESSION ? $this->session_hours : 24 * 7;
    }
}
