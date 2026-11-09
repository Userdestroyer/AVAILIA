<?php

namespace App\Enums;

/**
 * Сфера трат — у каждой свой лимит в тарифе (см. docs/LIMITS.md).
 */
enum UsageScope: string
{
    // Ответы в чате и фоновые вызовы сервисной модели — деньги в окнах «сессия + неделя»
    case Text = 'text';
    // Агентная и фоновая работа, голосовой ассистент — тоже окна, свой бюджет
    case Agent = 'agent';
    // Генерация картинок, видео, аудио — баланс токенов
    case Content = 'content';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Текст',
            self::Agent => 'Агенты',
            self::Content => 'Контент',
        };
    }
}
