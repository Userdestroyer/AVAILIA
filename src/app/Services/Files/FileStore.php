<?php

namespace App\Services\Files;

use App\Enums\FileSource;
use App\Models\User;
use App\Models\UserFile;
use GdImage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Единая точка входа файлов в файловое поле юзера: проверка, дедупликация (только среди его файлов),
 * хранение по пути с владельцем и превью для галереи.
 */
class FileStore
{
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    public function store(User $user, UploadedFile $upload, FileSource $source = FileSource::Uploaded): UserFile
    {
        $mime = $upload->getMimeType();
        $size = $upload->getSize();
        $sha256 = hash_file('sha256', $upload->getRealPath());

        // Тот же файл ещё раз — возвращаем уже загруженный
        if ($existing = $user->files()->where('sha256', $sha256)->first()) {
            return $existing;
        }

        [$width, $height] = in_array($mime, UserFile::IMAGE_MIMES, true) ? $this->dimensions($upload) : [null, null];

        $quota = config('files.quota_mb') * 1024 * 1024;
        if ($user->files()->sum('size') + $size > $quota) {
            throw ValidationException::withMessages(['file' => 'Файловое поле заполнено — удалите ненужные файлы.']);
        }

        $file = new UserFile([
            'source' => $source,
            'name' => $this->name($upload->getClientOriginalName(), $mime),
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'sha256' => $sha256,
        ]);
        $file->id = $file->newUniqueId();
        $file->user_id = $user->id;

        $directory = "files/{$user->id}";
        $file->path = Storage::disk('local')->putFileAs($directory, $upload, "{$file->id}.".self::EXTENSIONS[$mime]);
        $file->thumb_path = $width ? $this->thumbnail($upload->getRealPath(), "{$directory}/{$file->id}.thumb.webp", $width, $height) : null;

        try {
            $file->save();
        } catch (UniqueConstraintViolationException) {
            // Тот же файл пришёл параллельно — оставляем первый
            Storage::disk('local')->delete(array_filter([$file->path, $file->thumb_path]));

            return $user->files()->where('sha256', $sha256)->firstOrFail();
        }

        return $file;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(UploadedFile $upload): array
    {
        [$width, $height] = @getimagesize($upload->getRealPath()) ?: [0, 0];
        $max = config('files.max_side');

        if (! $width || ! $height) {
            throw ValidationException::withMessages(['file' => 'Картинка повреждена.']);
        }
        if ($width > $max || $height > $max) {
            throw ValidationException::withMessages(['file' => "Картинка больше {$max} px по стороне."]);
        }

        return [$width, $height];
    }

    /**
     * Превью для галереи; не вышло — галерея покажет оригинал.
     */
    private function thumbnail(string $source, string $path, int $width, int $height): ?string
    {
        $target = min($width, config('files.thumb_width'));
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image instanceof GdImage) {
            return null;
        }

        $thumb = imagescale($image, $target, max(1, (int) round($height * $target / $width)));
        imagedestroy($image);
        if (! $thumb instanceof GdImage || ! function_exists('imagewebp')) {
            return null;
        }

        ob_start();
        imagewebp($thumb, null, 80);
        imagedestroy($thumb);

        return Storage::disk('local')->put($path, (string) ob_get_clean()) ? $path : null;
    }

    /**
     * Имя для людей: без пути, не длиннее 200 символов, с расширением по настоящему типу.
     */
    private function name(string $original, string $mime): string
    {
        $base = Str::limit(pathinfo(basename($original), PATHINFO_FILENAME) ?: 'file', 190, '');

        return $base.'.'.self::EXTENSIONS[$mime];
    }
}
