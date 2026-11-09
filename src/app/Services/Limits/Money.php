<?php

namespace App\Services\Limits;

use App\Services\CurrencyConverter;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Лимиты считаются в рублях: трата в другой валюте переводится по курсу на дату траты.
 */
final class Money
{
    public static function toRub(string $amount, ?string $currency, ?CarbonInterface $at = null): string
    {
        if ($currency === null || $currency === 'RUB') {
            return $amount;
        }

        try {
            return app(CurrencyConverter::class)->convert($amount, $currency, 'RUB', $at ?? now());
        } catch (Throwable $e) {
            // Курса нет — лучше учесть «как есть», чем не учесть вовсе
            report($e);

            return $amount;
        }
    }
}
