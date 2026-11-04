<?php

namespace App\Models;

use App\Enums\PlanCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'description', 'price_monthly', 'currency',
    'daily_request_limit', 'monthly_token_limit', 'monthly_budget_usd',
    'is_public', 'sort',
])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'daily_request_limit' => 'integer',
            'monthly_token_limit' => 'integer',
            'monthly_budget_usd' => 'decimal:2',
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
}
