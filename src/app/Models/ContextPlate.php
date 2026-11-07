<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Картридж контекста пользователя: кусок «обо мне», который вставляется в системный промпт.
 */
#[Fillable(['title', 'content', 'is_pinned', 'sort'])]
class ContextPlate extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContextPlateVersion::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'context_plate_tag');
    }

    /**
     * Записать текущее содержимое в историю. Сохраняет человек явно (кнопкой) — каждое сохранение
     * осмысленный шаг и отдельная версия.
     */
    public function recordVersion(string $author = 'user', ?int $messageId = null): ContextPlateVersion
    {
        $version = $this->versions()->create(['content' => $this->content, 'author' => $author, 'message_id' => $messageId]);
        $this->forceFill(['current_version_id' => $version->id])->saveQuietly();

        return $version;
    }

    /**
     * Теги по именам — свои у пользователя, «Работа» и «работа» — один тег.
     *
     * @param  list<string>  $names
     */
    public function syncTags(array $names): void
    {
        $ids = collect($names)
            ->map(fn (string $name) => trim(preg_replace('/\s+/u', ' ', ltrim(trim($name), '#'))))
            ->filter()
            ->unique(fn (string $name) => Tag::slugOf($name))
            ->map(fn (string $name) => Tag::firstOrCreate(
                ['user_id' => $this->user_id, 'slug' => Tag::slugOf($name)],
                ['name' => $name],
            )->id);

        $this->tags()->sync($ids->all());
    }

    /**
     * Примерный размер в токенах — показать, сколько картридж добавит к каждому запросу.
     */
    public function approxTokens(): int
    {
        return (int) ceil(mb_strlen($this->title.$this->content) / 3.5);
    }
}
