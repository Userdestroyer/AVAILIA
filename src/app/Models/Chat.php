<?php

namespace App\Models;

use App\Enums\AiModelType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'ai_model_id', 'title', 'mode'])]
class Chat extends Model
{
    use HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            // Текст или картинки
            'mode' => AiModelType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
