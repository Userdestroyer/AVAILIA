<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Операция с балансом токенов контента; balance_after — остаток после неё.
 */
#[Fillable(['user_id', 'amount', 'balance_after', 'reason', 'comment', 'actor_id'])]
class TokenTransaction extends Model
{
    public const MONTHLY = 'monthly';

    public const ADMIN = 'admin';

    public const UPDATED_AT = null;
}
