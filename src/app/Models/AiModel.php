<?php

namespace App\Models;

use App\Enums\AiModelType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vendor_id', 'type', 'slug', 'name', 'description', 'context_window',
    'price_input', 'price_output', 'price_cache_read', 'price_cache_write', 'price_currency',
    'is_active', 'sort',
])]
class AiModel extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AiModelType::class,
            'context_window' => 'integer',
            'price_input' => 'decimal:4',
            'price_output' => 'decimal:4',
            'price_cache_read' => 'decimal:4',
            'price_cache_write' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Стоимость запроса в валюте цен модели (price_currency); цены — за 1M токенов.
     * Если у модели нет отдельной цены кэша, кэшированные токены считаются по цене ввода:
     * лучше переоценить, чем недосписать.
     */
    public function calculateCost(int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): string
    {
        $sum = '0';

        foreach ([
            [$input, $this->price_input],
            [$output, $this->price_output],
            [$cacheRead, $this->price_cache_read ?? $this->price_input],
            [$cacheWrite, $this->price_cache_write ?? $this->price_input],
        ] as [$tokens, $price]) {
            $sum = bcadd($sum, bcmul((string) $tokens, $price ?? '0', 8), 8);
        }

        return bcdiv($sum, '1000000', 8);
    }
}
