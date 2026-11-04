<?php

namespace App\Services\ExchangeRates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CbrSource implements ExchangeRateSource
{
    private const URL = 'https://www.cbr.ru/scripts/XML_daily.asp';

    public function name(): string
    {
        return 'cbr';
    }

    public function fetch(CarbonImmutable $date): array
    {
        $body = Http::timeout(10)
            ->retry(2, 500)
            ->get(self::URL, ['date_req' => $date->format('d/m/Y')])
            ->throw()
            ->body();

        // ЦБ отдаёт XML в windows-1251
        $body = mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
        $body = preg_replace('/encoding="[^"]+"/i', 'encoding="UTF-8"', $body, 1);

        $xml = simplexml_load_string($body);
        if ($xml === false || ! isset($xml['Date'])) {
            throw new RuntimeException('ЦБ вернул некорректный XML');
        }

        // Рублей за 1 единицу каждой валюты
        $rub = [];
        foreach ($xml->Valute as $valute) {
            $value = str_replace(',', '.', (string) $valute->Value);
            $rub[(string) $valute->CharCode] = bcdiv($value, (string) $valute->Nominal, 8);
        }

        if (! isset($rub['USD'])) {
            throw new RuntimeException('В ответе ЦБ нет курса USD');
        }

        // Переводим в «единиц валюты за 1 USD»
        $rates = ['RUB' => $rub['USD']];
        foreach ($rub as $code => $perUnit) {
            if ($code !== 'USD') {
                $rates[$code] = bcdiv($rub['USD'], $perUnit, 8);
            }
        }

        return [
            'date' => CarbonImmutable::createFromFormat('d.m.Y', (string) $xml['Date'])->startOfDay(),
            'rates' => $rates,
        ];
    }
}
