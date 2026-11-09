<?php

namespace App\Models;

use App\Enums\UsageScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Окно лимита (сессия или неделя): открывается первым запросом после предыдущего, копит трату.
 */
#[Fillable(['user_id', 'scope', 'kind', 'started_at', 'ends_at', 'spent'])]
class UsageWindow extends Model
{
    public const SESSION = 'session';

    public const WEEK = 'week';

    public const KINDS = [self::SESSION, self::WEEK];

    protected function casts(): array
    {
        return [
            'scope' => UsageScope::class,
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'spent' => 'decimal:6',
        ];
    }
}
