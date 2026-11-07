<?php

namespace App\Models;

use App\Enums\Capability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Возможность модели у провайдера и её ограничения.
 */
#[Fillable(['provider_model_id', 'capability', 'settings'])]
class ProviderModelCapability extends Model
{
    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
            'settings' => 'array',
        ];
    }

    /**
     * Ограничение: своё у строки или по умолчанию для возможности.
     */
    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? $this->capability->defaults()[$key] ?? null;
    }

    /**
     * Все ограничения с подставленными умолчаниями — для фронта.
     *
     * @return array<string, mixed>
     */
    public function limits(): array
    {
        return [...$this->capability->defaults(), ...($this->settings ?? [])];
    }
}
