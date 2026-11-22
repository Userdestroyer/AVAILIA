<?php

namespace App\Http\Controllers;

use App\Enums\FileSource;
use App\Models\UserFile;
use App\Services\Files\FileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Файловое поле юзера. Всё — через владельца: чужой файл — 404, его существование не подтверждаем.
 */
class FileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['source' => ['nullable', Rule::enum(FileSource::class)]]);

        $files = $request->user()->files()
            ->when($validated['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->latest()
            ->limit(200)
            ->get();

        return response()->json(['files' => $files->map->present()]);
    }

    public function store(Request $request, FileStore $store): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', [...UserFile::IMAGE_MIMES, ...UserFile::DOCUMENT_MIMES]),
                'max:'.config('files.max_kb'),
            ],
        ], [
            'file.mimetypes' => 'Можно прикрепить картинку (PNG, JPEG, WebP, GIF) или PDF.',
            'file.max' => 'Файл больше '.round(config('files.max_kb') / 1024).' МБ.',
        ]);

        $file = $store->store($request->user(), $request->file('file'));

        return response()->json(['file' => $file->present()], $file->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $file): StreamedResponse
    {
        $file = $request->user()->files()->findOrFail($file);

        return $this->send($file->path, $file->mime, $file->name);
    }

    public function thumb(Request $request, string $file): StreamedResponse
    {
        $file = $request->user()->files()->whereNotNull('thumb_path')->findOrFail($file);

        return $this->send($file->thumb_path, 'image/webp', $file->name);
    }

    private function send(string $path, string $mime, string $name): StreamedResponse
    {
        // Содержимое файла по этому адресу не меняется — браузер держит его у себя
        return Storage::disk('local')->response($path, $name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
