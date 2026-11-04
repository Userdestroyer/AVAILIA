<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonInterface;
use RuntimeException;

class CurrencyConverter
{
    /** @var array<string, string> */
    private array $memo = [];

    /**
     * Перевод суммы между валютами через USD по курсу на дату (или ближайшему более раннему).
     */
    public function convert(string $amount, string $from, string $to, ?CarbonInterface $date = null): string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return $amount;
        }

        $date ??= now();
        $usd = $from === 'USD' ? $amount : bcdiv($amount, $this->rate($from, $date), 8);

        return $to === 'USD' ? $usd : bcmul($usd, $this->rate($to, $date), 8);
    }

    /**
     * Единиц валюты за 1 USD на дату.
     */
    public function rate(string $currency, CarbonInterface $date): string
    {
        $day = $date->toDateString();

        return $this->memo["{$currency}:{$day}"] ??= ExchangeRate::where('currency', $currency)
            ->whereDate('date', '<=', $day)
            ->orderByDesc('date')
            ->value('rate')
            // Сообщение старше самого первого загруженного курса — берём самый ранний
            ?? ExchangeRate::where('currency', $currency)->orderBy('date')->value('rate')
            ?? throw new RuntimeException("Нет курса для {$currency}");
    }
}
