<?php

namespace App\Enums;

enum MessageStatus: string
{
    // Ответ ассистента создан Laravel, ждёт, пока Go-гейтвей начнёт стрим
    case Pending = 'pending';
    case Streaming = 'streaming';
    case Done = 'done';
    case Failed = 'failed';
}
