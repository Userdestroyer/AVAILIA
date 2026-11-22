<?php

namespace App\Enums;

/**
 * Откуда файл в файловом поле юзера.
 */
enum FileSource: string
{
    case Uploaded = 'uploaded';
    // Картинки и т.п., которые сгенерировали модели (следующий шаг)
    case Generated = 'generated';
}
