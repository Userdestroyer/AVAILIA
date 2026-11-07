<?php

namespace App\Services\Chat;

use App\Models\ContextPlate;
use Illuminate\Support\Collection;

/**
 * Картриджи контекста → системный промпт. Порядок стабильный (как в списке картриджей):
 * одинаковое начало промпта попадает в кэш провайдера — длинный контекст дешевле и не тормозит ответ.
 */
class ContextPrompt
{
    private const INTRO = 'Ниже — картриджи контекста: сведения о пользователе, которые он сам подключил к разговору. '
        .'Учитывай их, когда это помогает ответу; не пересказывай и не упоминай их без нужды.';

    /**
     * @param  Collection<int, ContextPlate>  $plates
     */
    public static function build(Collection $plates): ?string
    {
        if ($plates->isEmpty()) {
            return null;
        }

        $blocks = $plates->map(function (ContextPlate $plate) {
            $tags = $plate->tags->pluck('name')->implode(', ');
            $attributes = 'title="'.self::attribute($plate->title).'"'.($tags !== '' ? ' tags="'.self::attribute($tags).'"' : '');

            return "<cartridge {$attributes}>\n".trim($plate->content)."\n</cartridge>";
        });

        return self::INTRO."\n\n".$blocks->implode("\n\n");
    }

    private static function attribute(string $value): string
    {
        return str_replace(['"', "\n"], ['\'', ' '], $value);
    }
}
