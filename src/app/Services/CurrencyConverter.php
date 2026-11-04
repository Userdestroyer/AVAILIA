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
     * Перевод суммы из USD в валюту по курсу на дату (или ближайшему более раннему).
     */
    public function fromUsd(string $amountUsd, string $currency, ?CarbonInterface $date = null): string
    {
        $currency = strtoupper($currency);

        if ($currency === 'USD') {
            return $amountUsd;
        }

        return bcmul($amountUsd, $this->rate($currency, $date ?? now()), 8);
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
