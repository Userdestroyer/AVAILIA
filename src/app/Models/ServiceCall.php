<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Фоновый вызов сервисной модели: для чего, чем, сколько стоил и длился.
 */
#[Fillable([
    'user_id', 'purpose', 'provider_model_id', 'status',
    'input_tokens', 'output_tokens', 'cost', 'cost_currency', 'duration_ms',
])]
class ServiceCall extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:8',
        ];
    }
}
