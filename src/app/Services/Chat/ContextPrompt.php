<?php

namespace App\Services\Chat;

use App\Enums\MessageRole;
use App\Models\ContextPlate;
use App\Models\ContextPlateVersion;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Картриджи контекста в разговоре. Картридж прикладывают к сообщению — как документ: для модели он стоит
 * в начале того сообщения, в той версии, что была при прикреплении, и действует с этого места.
 * Начало истории поэтому не меняется, когда картридж прикладывают позже, — оно остаётся в кэше провайдера.
 */
class ContextPrompt
{
    // Неподвижный системный промпт — только в разговорах с картриджами
    public const INTRO = 'Пользователь прикладывает к своим сообщениям картриджи контекста — сведения о себе в тегах <cartridge>. '
        .'Картридж действует с того места разговора, где его приложили; более поздняя версия картриджа с тем же названием заменяет прежнюю. '
        .'Учитывай их, когда это помогает ответу; не пересказывай и не упоминай их без нужды.';

    /**
     * Что приложено впервые: у каждого вопроса остаются только картриджи (в своей версии), которых выше ещё не было.
     * В чатах до этой схемы картриджи записаны у каждого вопроса — так они считаются приложенными там, где появились.
     * Меняется только в памяти, в БД не сохраняется.
     *
     * @param  iterable<Message>  $messages  разговор по порядку
     */
    public static function firstAttached(iterable $messages): void
    {
        $seen = [];

        foreach ($messages as $message) {
            if ($message->role !== MessageRole::User || ! $message->context) {
                continue;
            }

            $fresh = array_values(array_filter($message->context, fn (array $entry) => ! isset($seen[self::key($entry)])));
            foreach ($message->context as $entry) {
                $seen[self::key($entry)] = true;
            }
            $message->context = $fresh ?: null;
        }
    }

    /**
     * Разговор для модели: картриджи — в начале сообщений, к которым их приложили, системный промпт — инструкция.
     * Только картриджи этого юзера — фильтр в самом запросе.
     *
     * @param  Collection<int, Message>  $conversation  по порядку
     * @return array{0: ?Message, 1: Collection<int, Message>} системное сообщение и разговор (сообщения с картриджами — копии)
     */
    public static function inline(User $user, Collection $conversation): array
    {
        self::firstAttached($conversation);
        $entries = $conversation->pluck('context')->filter()->flatten(1);
        if ($entries->isEmpty()) {
            return [null, $conversation];
        }

        $plates = self::resolve($user, $entries);
        $inlined = $conversation->map(function (Message $message) use ($plates) {
            $blocks = collect($message->context ?? [])->map(fn (array $entry) => $plates->get(self::key($entry)))->filter()->map(self::block(...));
            if ($blocks->isEmpty()) {
                return $message;
            }

            // Копия — с теми же файлами; в БД вопрос остаётся как был
            $copy = clone $message;
            $question = trim((string) $message->content);
            $copy->content = $blocks->implode("\n\n").($question !== '' ? "\n\n".$question : '');

            return $copy;
        });

        return [new Message(['role' => MessageRole::System, 'content' => self::INTRO]), $inlined];
    }

    /**
     * Картриджи, приложенные к этому вопросу, в своих версиях — чтобы показать вопрос целиком.
     *
     * @return Collection<int, ContextPlate> с содержимым версии (в БД не сохраняется)
     */
    public static function recorded(User $user, Message $question): Collection
    {
        $questions = Message::where('chat_id', $question->chat_id)
            ->where('role', MessageRole::User)
            ->where('id', '<=', $question->id)
            ->orderBy('id')
            ->get(['id', 'role', 'context']);
        self::firstAttached($questions);
        $entries = collect($questions->last()?->context ?? []);

        return $entries->isEmpty() ? collect() : self::resolve($user, $entries)->values();
    }

    /**
     * Картриджи юзера в записанных версиях: «plate_id:version_id» → картридж с содержимым той версии.
     *
     * @param  Collection<int, array{plate_id: int, version_id: ?int, title: string}>  $entries
     * @return Collection<string, ContextPlate>
     */
    private static function resolve(User $user, Collection $entries): Collection
    {
        $plates = $user->plates()->withTrashed()->whereIn('id', $entries->pluck('plate_id')->unique())->with('tags')->get()->keyBy('id');
        $versions = ContextPlateVersion::whereIn('id', $entries->pluck('version_id')->filter()->unique())
            ->whereIn('context_plate_id', $plates->keys())
            ->pluck('content', 'id');

        return $entries
            ->filter(fn (array $entry) => $plates->has($entry['plate_id']))
            ->mapWithKeys(fn (array $entry) => [self::key($entry) => tap(clone $plates[$entry['plate_id']], function (ContextPlate $plate) use ($entry, $versions) {
                $plate->title = $entry['title'];
                $plate->content = $versions->get($entry['version_id'] ?? 0, $plate->content);
            })]);
    }

    private static function block(ContextPlate $plate): string
    {
        $tags = $plate->tags->pluck('name')->implode(', ');
        $attributes = 'title="'.self::attribute($plate->title).'"'.($tags !== '' ? ' tags="'.self::attribute($tags).'"' : '');

        return "<cartridge {$attributes}>\n".trim($plate->content)."\n</cartridge>";
    }

    /**
     * @param  array{plate_id: int, version_id?: ?int}  $entry
     */
    private static function key(array $entry): string
    {
        return $entry['plate_id'].':'.($entry['version_id'] ?? '');
    }

    private static function attribute(string $value): string
    {
        return str_replace(['"', "\n"], ['\'', ' '], $value);
    }
}
