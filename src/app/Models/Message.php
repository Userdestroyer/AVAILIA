<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Services\CurrencyConverter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'chat_id', 'parent_id', 'ai_model_id', 'provider_id', 'role', 'status', 'content', 'error',
    'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'web_searches', 'search_log',
    'cost', 'cost_currency', 'ttft_ms', 'client_ttft_ms', 'duration_ms', 'context',
])]
class Message extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'status' => MessageStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'web_searches' => 'integer',
            'search_log' => 'array',
            'context' => 'array',
            'cost' => 'decimal:8',
            'ttft_ms' => 'integer',
            'client_ttft_ms' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Вопрос, на который отвечает ответ ассистента.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Ответы на этот вопрос.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    /**
     * Стоимость в нужной валюте по курсу на дату сообщения.
     */
    public function costIn(string $currency): ?string
    {
        if ($this->cost === null) {
            return null;
        }

        return app(CurrencyConverter::class)->convert($this->cost, $this->cost_currency, $currency, $this->created_at);
    }
}
