<?php

namespace App\Models;

use App\Enums\Capability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель у конкретного провайдера: id в его API, источник, лимиты, возможности и расценки
 * (любая может быть пустой).
 */
#[Fillable([
    'provider_id', 'ai_model_id', 'external_id', 'upstream', 'context_length', 'max_output_tokens',
    'price_input', 'price_output', 'price_cache_read', 'price_cache_write', 'price_web_search', 'price_currency',
    'is_active',
])]
class ProviderModel extends Model
{
    protected function casts(): array
    {
        return [
            'price_input' => 'decimal:6',
            'price_output' => 'decimal:6',
            'price_cache_read' => 'decimal:6',
            'price_cache_write' => 'decimal:6',
            'price_web_search' => 'decimal:4',
            'context_length' => 'integer',
            'max_output_tokens' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(ProviderModelCapability::class);
    }

    /**
     * Возможность с ограничениями или null — модель этого не умеет (параметр не отправляем).
     */
    public function capability(Capability $capability): ?ProviderModelCapability
    {
        return $this->capabilities->first(fn (ProviderModelCapability $row) => $row->capability === $capability);
    }

    public function supports(Capability $capability): bool
    {
        return $this->capability($capability) !== null;
    }

    /**
     * Привести набор возможностей к списку: лишние удалить, новые добавить.
     * Ограничения у оставшихся сохраняются, если не переданы новые.
     *
     * @param  array<string, array<string, mixed>|null>  $capabilities  capability => settings (null — не трогать/по умолчанию)
     */
    public function syncCapabilities(array $capabilities): void
    {
        $this->capabilities()->whereNotIn('capability', array_keys($capabilities))->delete();

        foreach ($capabilities as $capability => $settings) {
            $row = $this->capabilities()->firstOrNew(['capability' => $capability]);
            if (! $row->exists || $settings !== null) {
                $row->settings = $settings;
            }
            $row->save();
        }

        $this->unsetRelation('capabilities');
    }

    public function hasPrices(): bool
    {
        return $this->price_input !== null && $this->price_output !== null;
    }

    /**
     * Стоимость по расценкам (цены — за 1M токенов) или null, если расценок нет.
     * Кэш без своей цены считается по цене ввода: лучше переоценить, чем недосписать.
     */
    public function calculateCost(int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0, int $webSearches = 0): ?string
    {
        if (! $this->hasPrices()) {
            return null;
        }

        $sum = '0';
        foreach ([
            [$input, $this->price_input],
            [$output, $this->price_output],
            [$cacheRead, $this->price_cache_read ?? $this->price_input],
            [$cacheWrite, $this->price_cache_write ?? $this->price_input],
        ] as [$tokens, $price]) {
            $sum = bcadd($sum, bcmul((string) $tokens, $price, 8), 8);
        }

        $cost = bcdiv($sum, '1000000', 8);

        return bcadd($cost, bcmul((string) $webSearches, $this->price_web_search ?? '0', 8), 8);
    }
}
