<?php

namespace App\Services\ExchangeRates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenErApiSource implements ExchangeRateSource
{
    private const URL = 'https://open.er-api.com/v6/latest/USD';

    public function name(): string
    {
        return 'open_er_api';
    }

    /**
     * Бесплатный тариф отдаёт только текущий курс — дата из запроса игнорируется.
     */
    public function fetch(CarbonImmutable $date): array
    {
        $data = Http::timeout(10)->retry(2, 500)->get(self::URL)->throw()->json();

        if (($data['result'] ?? null) !== 'success') {
            throw new RuntimeException('open.er-api вернул ошибку: '.($data['error-type'] ?? 'unknown'));
        }

        $rates = [];
        foreach ($data['rates'] as $code => $rate) {
            if ($code !== 'USD') {
                $rates[$code] = number_format((float) $rate, 8, '.', '');
            }
        }

        return [
            'date' => CarbonImmutable::createFromTimestamp($data['time_last_update_unix'])->startOfDay(),
            'rates' => $rates,
        ];
    }
}
