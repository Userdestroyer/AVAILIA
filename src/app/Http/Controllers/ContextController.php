<?php

namespace App\Http\Controllers;

use App\Models\ContextPlate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * Картриджи контекста «Обо мне» (модалка профиля). Всё — только через владельца: чужой картридж — 404.
 * Сохраняет человек явно, кнопкой; каждое сохранение содержимого — версия в истории.
 */
class ContextController extends Controller
{
    /**
     * Картриджи с содержимым для редактора — фронт грузит их заранее, пока браузер простаивает.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'plates' => $request->user()->plates()->with('tags')->withCount('versions')->orderBy('sort')->orderBy('id')->get()
                ->map(fn (ContextPlate $plate) => self::present($plate)),
            'tags' => $request->user()->tags()->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(required: true));

        $plate = $request->user()->plates()->create([
            ...collect($validated)->only(['title', 'is_pinned'])->all(),
            'content' => $validated['content'] ?? '',
            'sort' => (int) $request->user()->plates()->max('sort') + 1,
        ]);
        $plate->recordVersion();
        $plate->syncTags($validated['tags'] ?? []);

        return response()->json(['plate' => self::present($plate->load('tags')->loadCount('versions'))], 201);
    }

    public function update(Request $request, string $plate): JsonResponse
    {
        $plate = $request->user()->plates()->findOrFail($plate);
        $validated = $request->validate($this->rules(required: false));

        $plate->fill(collect($validated)->only(['title', 'content', 'is_pinned'])->map(fn ($v, $k) => $k === 'content' ? (string) $v : $v)->all());
        $contentChanged = $plate->isDirty('content');
        $plate->save();

        if ($contentChanged) {
            $plate->recordVersion();
        }
        if (array_key_exists('tags', $validated)) {
            $plate->syncTags($validated['tags'] ?? []);
        }

        return response()->json(['plate' => self::present($plate->load('tags')->loadCount('versions'))]);
    }

    public function destroy(Request $request, string $plate): HttpResponse
    {
        // Мягко: версии остаются, ссылки из сообщений («что ушло в модель») не рвутся
        $request->user()->plates()->findOrFail($plate)->delete();

        return response()->noContent();
    }

    /**
     * Для страниц: картридж без лишнего; content — только где он нужен (редактор).
     */
    public static function present(ContextPlate $plate, bool $withContent = true): array
    {
        return [
            ...$plate->only('id', 'title', 'is_pinned'),
            ...($withContent ? ['content' => $plate->content, 'versions' => $plate->versions_count ?? null] : []),
            'tags' => $plate->tags->pluck('name')->values(),
            'tokens' => $plate->approxTokens(),
        ];
    }

    private function rules(bool $required): array
    {
        return [
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:80'],
            'content' => ['nullable', 'string', 'max:20000'],
            'is_pinned' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:12'],
            'tags.*' => ['string', 'max:40'],
        ];
    }
}
