<?php

namespace App\Http\Controllers;

use App\Enums\AiModelType;
use App\Enums\MessageRole;
use App\Http\Requests\StoreImageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Provider;
use App\Models\ProviderModel;
use App\Models\Vendor;
use App\Services\Images\ImageGenerator;
use App\Services\Images\ImagePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Чат картинок: тот же чат, только ответ модели — картинка. Модели картинок — у Polza
 * (Media API), независимо от провайдера текста.
 */
class ImageController extends Controller
{
    public function index(): Response
    {
        return $this->render();
    }

    public function show(Request $request, string $chat): Response|RedirectResponse
    {
        // Чужой чат — 404; текстовый — в текстовый режим
        $chat = $request->user()->chats()->findOrFail($chat);
        if ($chat->mode !== AiModelType::Image) {
            return redirect("/text/{$chat->id}");
        }

        return $this->render($chat);
    }

    public function store(StoreImageRequest $request, ImageGenerator $generator): JsonResponse
    {
        $user = $request->user();
        $offer = $this->offers()->firstWhere('ai_model_id', $request->integer('ai_model_id'));
        if (! $offer) {
            return response()->json(['message' => 'Эта модель сейчас недоступна — выберите другую.'], 422);
        }

        $chat = $request->filled('chat_id')
            ? $user->chats()->where('mode', AiModelType::Image)->findOrFail($request->input('chat_id'))
            : null;

        // Референсы — только свои картинки и в порядке прикрепления; чужой id — как несуществующий
        $fileIds = $request->input('file_ids') ?? [];
        $files = $user->files()->whereIn('id', $fileIds)->get()->filter->isImage()->keyBy('id');
        if ($files->count() !== count($fileIds)) {
            return response()->json(['message' => 'Референс не найден или это не картинка — прикрепите заново.'], 422);
        }
        $limit = ImageGenerator::referenceLimit($offer);
        if (count($fileIds) > $limit) {
            return response()->json(['message' => $limit ? "{$offer->aiModel->name} берёт не больше {$limit} референсов." : "{$offer->aiModel->name} не принимает референсы."], 422);
        }
        $maxPrompt = (int) ($offer->parameters['prompt']['max_length'] ?? 20000);
        if (mb_strlen($request->string('prompt')) > $maxPrompt) {
            return response()->json(['message' => "Промпт для {$offer->aiModel->name} — не длиннее {$maxPrompt} символов."], 422);
        }

        $started = $generator->start(
            $user,
            $chat,
            $offer,
            (string) $request->string('prompt')->trim(),
            collect($fileIds)->map(fn (string $id) => $files[$id])->values(),
            ImageGenerator::params($offer, $request->input('params') ?? []),
        );

        return response()->json([
            'chat' => $started['chat']->only('id', 'title'),
            'user_message' => MessageResource::make($started['question']),
            'answer' => MessageResource::make($started['answer']->setRelation('aiModel', $offer->aiModel)),
        ], 201);
    }

    /**
     * Ответ-картинка: пока рисуется, страница спрашивает статус (раз в пару секунд).
     */
    public function status(Request $request, Message $message): JsonResponse
    {
        abort_unless($message->chat?->user_id === $request->user()->id && $message->role === MessageRole::Assistant, 404);

        return response()->json(['message' => MessageResource::make($message->load(['files', 'aiModel.vendor']))]);
    }

    private function render(?Chat $chat = null): Response
    {
        $messages = $chat?->messages()->with(['aiModel.vendor', 'chat.user', 'files'])->orderBy('id')->get();

        return Inertia::render('Images/Index', [
            'vendors' => $this->vendors(),
            'chat' => $chat?->only('id', 'title', 'ai_model_id'),
            'messages' => $messages ? MessageResource::collection($messages)->resolve() : [],
            'fileLimits' => ['max_bytes' => config('files.max_kb') * 1024, 'per_message' => 16],
        ]);
    }

    /**
     * Включённые модели картинок у Polza.
     */
    private function offers()
    {
        return ProviderModel::where('provider_id', Provider::where('slug', 'polza')->value('id'))
            ->where('is_active', true)
            ->whereHas('aiModel', fn ($query) => $query->where('type', AiModelType::Image)->where('is_active', true))
            ->with('aiModel.vendor')
            ->get();
    }

    /**
     * Вендоры и их модели картинок — с тем, что нужно панели: параметры, предел референсов, прайс для оценки.
     */
    private function vendors()
    {
        $offers = $this->offers()->keyBy('ai_model_id');

        return Vendor::where('is_active', true)
            ->orderBy('sort')
            ->with(['models' => fn ($query) => $query->where('type', AiModelType::Image)->where('is_active', true)->whereIn('id', $offers->keys())->orderBy('sort')])
            ->get()
            ->filter(fn (Vendor $vendor) => $vendor->models->isNotEmpty())
            ->map(fn (Vendor $vendor) => [
                'slug' => $vendor->slug,
                'product' => $vendor->product ?? $vendor->name,
                'models' => $vendor->models->map(fn ($model) => [
                    ...$model->only('id', 'name', 'description'),
                    'parameters' => (object) array_intersect_key($offers[$model->id]->parameters ?? [], array_flip(['aspect_ratio', 'image_resolution', 'quality', 'prompt'])),
                    'references' => ImageGenerator::referenceLimit($offers[$model->id]),
                    'pricing' => (object) ($offers[$model->id]->pricing ?? []),
                    'price_label' => ImagePrice::label($offers[$model->id]->pricing ?? []),
                    'capabilities' => (object) [],
                ])->values(),
            ])
            ->values();
    }
}
