<?php

namespace App\Models;

use App\Enums\FileSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Файл из файлового поля юзера. Лежит на приватном диске по пути с владельцем,
 * наружу — только через FileController с проверкой владельца.
 */
#[Fillable(['user_id', 'source', 'name', 'mime', 'size', 'width', 'height', 'sha256', 'path', 'thumb_path'])]
class UserFile extends Model
{
    use HasUuids;

    public const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public const DOCUMENT_MIMES = ['application/pdf'];

    // Содержимое в base64 — один раз на запрос: мульти-запрос шлёт тот же файл каждой модели
    private ?string $encoded = null;

    protected function casts(): array
    {
        return [
            'source' => FileSource::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return in_array($this->mime, self::IMAGE_MIMES, true);
    }

    public function base64(): string
    {
        return $this->encoded ??= base64_encode(Storage::disk('local')->get($this->path));
    }

    public function dataUri(): string
    {
        return "data:{$this->mime};base64,".$this->base64();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            ...$this->only('id', 'name', 'mime', 'size', 'width', 'height'),
            'source' => $this->source,
            'kind' => $this->isImage() ? 'image' : 'document',
            'url' => route('files.show', $this->id),
            'thumb_url' => $this->thumb_path ? route('files.thumb', $this->id) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
