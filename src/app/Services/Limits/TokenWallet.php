<?php

namespace App\Services\Limits;

use App\Models\TokenTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Баланс токенов контента: начисления (по тарифу раз в месяц, админом, покупка) и списания.
 * Каждая операция — строка в token_transactions с остатком после; баланс меняется атомарно.
 */
class TokenWallet
{
    public function grant(User $user, int $amount, string $reason, ?User $actor = null, ?string $comment = null): TokenTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason, $actor, $comment) {
            User::whereKey($user->id)->increment('content_tokens', $amount);
            $balance = (int) User::whereKey($user->id)->value('content_tokens');
            $user->content_tokens = $balance;

            return TokenTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $balance,
                'reason' => $reason,
                'comment' => $comment,
                'actor_id' => $actor?->id,
            ]);
        });
    }

    /**
     * Ежемесячное начисление по тарифу, если с прошлого прошёл месяц (или его не было).
     * Неиспользованные токены копятся. Повторный вызов в том же месяце ничего не делает.
     */
    public function grantMonthlyIfDue(User $user): bool
    {
        $amount = (int) $user->effectivePlan()->content_tokens_monthly;
        if ($amount <= 0 || ($user->tokens_granted_at && $user->tokens_granted_at->addMonthNoOverflow()->isFuture())) {
            return false;
        }

        DB::transaction(function () use ($user, $amount) {
            $this->grant($user, $amount, TokenTransaction::MONTHLY);
            $user->forceFill(['tokens_granted_at' => now()])->save();
        });

        return true;
    }
}
