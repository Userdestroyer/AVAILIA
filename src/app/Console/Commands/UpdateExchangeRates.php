<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Services\ExchangeRates\CbrSource;
use App\Services\ExchangeRates\ExchangeRateSource;
use App\Services\ExchangeRates\OpenErApiSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('exchange-rates:update {--date= : Дата курса (Y-m-d), по умолчанию сегодня}')]
#[Description('Загрузить курсы валют к USD (первый доступный источник из config/exchange_rates.php)')]
class UpdateExchangeRates extends Command
{
    private const SOURCES = [
        'cbr' => CbrSource::class,
        'open_er_api' => OpenErApiSource::class,
    ];

    public function handle(): int
    {
        $date = CarbonImmutable::parse($this->option('date') ?? 'today');

        foreach (config('exchange_rates.sources') as $name) {
            /** @var ExchangeRateSource $source */
            $source = app(self::SOURCES[trim($name)]);

            try {
                $result = $source->fetch($date);
            } catch (Throwable $e) {
                $this->warn("{$source->name()}: {$e->getMessage()}");

                continue;
            }

            $now = now();
            $rows = collect($result['rates'])->map(fn (string $rate, string $currency) => [
                'date' => $result['date']->toDateString(),
                'currency' => $currency,
                'rate' => $rate,
                'source' => $source->name(),
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all();

            ExchangeRate::upsert($rows, ['date', 'currency'], ['rate', 'source', 'updated_at']);

            $this->info(sprintf(
                '%s: %d валют на %s, 1 USD = %s RUB',
                $source->name(), count($rows), $result['date']->toDateString(), $result['rates']['RUB'] ?? '?',
            ));

            return self::SUCCESS;
        }

        $this->error('Ни один источник курсов не ответил');

        return self::FAILURE;
    }
}
