<?php

namespace App\Services\ExchangeRates;

use Carbon\CarbonImmutable;

interface ExchangeRateSource
{
    public function name(): string;

    /**
     * Курсы на дату: сколько единиц валюты за 1 USD.
     * Дата в ответе — та, на которую источник реально опубликовал курс
     * (в выходные ЦБ отдаёт курс последнего рабочего дня).
     *
     * @return array{date: CarbonImmutable, rates: array<string, string>}
     */
    public function fetch(CarbonImmutable $date): array;
}
