<?php

namespace App\Models;

use App\Enums\AiModelType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['vendor_id', 'type', 'slug', 'name', 'description', 'context_window', 'is_active', 'sort'])]
class AiModel extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AiModelType::class,
            'context_window' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Где эта модель доступна: id и расценки у каждого провайдера.
     */
    public function offerings(): HasMany
    {
        return $this->hasMany(ProviderModel::class);
    }
}
