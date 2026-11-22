<?php

namespace App\Console\Commands;

use App\Enums\AiModelType;
use App\Services\Ai\PolzaCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('models:import {--limit= : Сколько самых свежих моделей каждого вендора смотреть (по умолчанию ai.import.per_vendor)}')]
#[Description('Новые модели текста и картинок из каталога Polza — скрытыми, с ценами, лимитами и возможностями; у подключённых обновляет цены')]
class ImportModels extends Command
{
    public function handle(PolzaCatalog $catalog): int
    {
        $limit = (int) ($this->option('limit') ?: config('ai.import.per_vendor'));
        $added = [...$catalog->import($limit), ...$catalog->import($limit, AiModelType::Image)];
        $updated = $catalog->sync();

        if ($added) {
            $this->table(['Вендор', 'ID у Polza', 'Название'], array_map(fn (array $row) => array_values($row), $added));
            $this->info('Добавлено скрытыми: '.count($added).' — включите нужные в админке («Модели и цены»).');
        } else {
            $this->info('Новых моделей нет.');
        }
        $this->info("Цены и возможности обновлены у подключённых: {$updated}");

        return self::SUCCESS;
    }
}
