<?php

namespace App\Enums;

/**
 * Что модель умеет у конкретного провайдера. Новая возможность — новый case
 * (и код, который её использует); строки в provider_model_capabilities, без миграций.
 */
enum Capability: string
{
    // Принимает temperature; settings: {min, max}
    case Temperature = 'temperature';
    // Рассуждает с регулируемой глубиной (reasoning_effort)
    case Reasoning = 'reasoning';
    // Картинки во входных сообщениях
    case Images = 'images';
    // Файлы (PDF и т.п.) во входных сообщениях
    case Files = 'files';

    public function label(): string
    {
        return match ($this) {
            self::Temperature => 'Температура',
            self::Reasoning => 'Рассуждения',
            self::Images => 'Картинки',
            self::Files => 'Файлы',
        };
    }

    /**
     * Ограничения по умолчанию — когда у строки свои не заданы.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Temperature => ['min' => 0, 'max' => 2],
            default => [],
        };
    }
}
