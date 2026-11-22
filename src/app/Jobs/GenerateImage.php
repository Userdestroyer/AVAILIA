<?php

namespace App\Jobs;

use App\Services\Images\ImageGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Шаг генерации картинки: отправить задание или спросить статус. Пока рисуется — ставит себя
 * в очередь снова через несколько секунд; воркер не держит соединение и не спит.
 */
class GenerateImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $answerId) {}

    public function handle(ImageGenerator $generator): void
    {
        $generator->advance($this->answerId);
    }
}
