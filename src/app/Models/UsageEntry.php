<?php

namespace App\Models;

use App\Enums\UsageScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Трата в журнале: сфера, сумма в ₽, источник.
 */
#[Fillable(['user_id', 'scope', 'amount', 'estimated', 'source_type', 'source_id'])]
class UsageEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'scope' => UsageScope::class,
            'amount' => 'decimal:6',
            'estimated' => 'boolean',
        ];
    }
}
