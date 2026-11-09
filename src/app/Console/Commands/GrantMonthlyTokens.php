<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Limits\TokenWallet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('limits:grant-tokens')]
#[Description('Ежемесячное начисление токенов контента по тарифу — тем, у кого с прошлого прошёл месяц')]
class GrantMonthlyTokens extends Command
{
    public function handle(TokenWallet $wallet): int
    {
        $granted = 0;
        User::with('plan')->chunkById(500, function ($users) use ($wallet, &$granted) {
            foreach ($users as $user) {
                $granted += (int) $wallet->grantMonthlyIfDue($user);
            }
        });

        $this->info("Начислено пользователям: {$granted}");

        return self::SUCCESS;
    }
}
