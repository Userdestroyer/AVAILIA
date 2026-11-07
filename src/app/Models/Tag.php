<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Тег пользователя — общая ось для картриджей, а дальше файлов, чатов и проектов.
 */
#[Fillable(['user_id', 'name', 'slug'])]
class Tag extends Model
{
    public function plates(): BelongsToMany
    {
        return $this->belongsToMany(ContextPlate::class, 'context_plate_tag');
    }

    public static function slugOf(string $name): string
    {
        return mb_substr(mb_strtolower(trim(preg_replace('/\s+/u', ' ', ltrim($name, '#')))), 0, 40);
    }
}
