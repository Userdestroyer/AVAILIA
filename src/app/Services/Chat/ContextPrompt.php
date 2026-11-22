<?php

namespace App\Services\Chat;

use App\Models\ContextPlate;
use App\Models\ContextPlateVersion;
use App\Models\Message;
use App\Models\User;
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

    /**
     * Картриджи вопроса в тех версиях, что ушли с ним в модель (по истории версий): для регенерации
     * и чтобы показать вопрос целиком. Только картриджи этого юзера — фильтр в самом запросе.
     *
     * @return Collection<int, ContextPlate> с содержимым версии (в БД не сохраняется)
     */
    public static function recorded(User $user, Message $question): Collection
    {
        $entries = collect($question->context ?? []);
        if ($entries->isEmpty()) {
            return collect();
        }

        $plates = $user->plates()->withTrashed()->whereIn('id', $entries->pluck('plate_id'))->with('tags')->get()->keyBy('id');
        $versions = ContextPlateVersion::whereIn('id', $entries->pluck('version_id')->filter())
            ->whereIn('context_plate_id', $plates->keys())
            ->pluck('content', 'id');

        return $entries
            ->filter(fn (array $entry) => $plates->has($entry['plate_id']))
            ->map(fn (array $entry) => tap(clone $plates[$entry['plate_id']], function (ContextPlate $plate) use ($entry, $versions) {
                $plate->title = $entry['title'];
                $plate->content = $versions->get($entry['version_id'] ?? 0, $plate->content);
            }))
            ->values();
    }

    private static function attribute(string $value): string
    {
        return str_replace(['"', "\n"], ['\'', ' '], $value);
    }
}
