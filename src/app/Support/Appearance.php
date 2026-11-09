<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Оформление интерфейса: стиль (JIVA — Y2K-стекло, classic — матовое стекло), светлая/тёмная
 * схема и шрифт акцентов. Известно до первого кадра (атрибуты <html>) — без вспышки чужого стиля:
 * выбранное в аккаунте, иначе в этом браузере (куки — и для страницы входа), иначе по умолчанию.
 */
final class Appearance
{
    public const OPTIONS = [
        'style' => ['jiva', 'classic'],
        'theme' => ['light', 'dark'],
        'font' => ['jura', 'exo2', 'unbounded'],
    ];

    public const DEFAULTS = ['style' => 'jiva', 'theme' => 'light', 'font' => 'jura'];

    /**
     * @return array{style: string, theme: string, font: string}
     */
    public static function resolve(?User $user, Request $request): array
    {
        $resolved = [];

        foreach (self::OPTIONS as $key => $allowed) {
            $value = $user?->settings[$key] ?? $request->cookie($key);
            $resolved[$key] = in_array($value, $allowed, true) ? $value : self::DEFAULTS[$key];
        }

        return $resolved;
    }
}
