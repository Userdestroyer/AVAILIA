<?php

namespace App\Http\Controllers;

use App\Enums\AiModelType;
use App\Enums\Capability;
use App\Enums\MessageRole;
use App\Models\ContextPlate;
use App\Http\Resources\MessageResource;
use App\Models\Chat;
use App\Models\ProviderModel;
use App\Models\Vendor;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ProviderRegistry;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class TextController extends Controller
{
    public function __construct(private ProviderRegistry $providers) {}

    public function index(): Response
    {
        return $this->render();
    }

    public function show(Request $request, string $chat): Response
    {
        // Чужой чат — 404, чтобы не подтверждать его существование
        $chat = $request->user()->chats()->findOrFail($chat);

        return $this->render($chat);
    }

    /**
     * Мягкое удаление: чат пропадает у юзера, но остаётся в БД (вместе со стоимостью ответов).
     */
    public function destroy(Request $request, string $chat): HttpResponse
    {
        $request->user()->chats()->findOrFail($chat)->delete();

        return response()->noContent();
    }

    private function render(?Chat $chat = null): Response
    {
        $messages = $chat?->messages()->with(['aiModel.vendor', 'chat.user'])->orderBy('id')->get();
        // Картриджи, подключённые к последнему вопросу, — чтобы продолжить чат с тем же контекстом
        $lastContext = $messages?->where('role', MessageRole::User)->last()?->context;

        return Inertia::render('Text/Index', [
            'vendors' => $this->vendors(),
            // Без содержимого: для чипов над полем ввода и подсказок `#` хватает названий и тегов
            'plates' => auth()->user()->plates()->with('tags')->orderBy('sort')->orderBy('id')->get()
                ->map(fn (ContextPlate $plate) => ContextController::present($plate, withContent: false)),
            'chat' => $chat ? [
                ...$chat->only('id', 'title', 'ai_model_id'),
                'plate_ids' => $lastContext ? array_column($lastContext, 'plate_id') : [],
            ] : null,
            'messages' => $messages ? MessageResource::collection($messages)->resolve() : [],
        ]);
    }

    /**
     * Вендоры и их модели, доступные у активного провайдера, — с его расценками
     * (любая может быть пустой: модель выбирается, стоимость тогда от провайдера или неизвестна).
     */
    private function vendors()
    {
        $provider = $this->providers->provider($this->providers->activeSlug());
        $offers = $this->providers->active()->offerings()->where('is_active', true)->with('capabilities')->get()->keyBy('ai_model_id');

        return Vendor::where('is_active', true)
            ->orderBy('sort')
            ->with(['models' => fn ($query) => $query
                ->where('type', AiModelType::Text)
                ->where('is_active', true)
                ->whereIn('id', $offers->keys())
                ->orderBy('sort'),
            ])
            ->get()
            ->filter(fn (Vendor $vendor) => $vendor->models->isNotEmpty())
            ->map(fn (Vendor $vendor) => [
                'slug' => $vendor->slug,
                'product' => $vendor->product ?? $vendor->name,
                'models' => $vendor->models->map(function ($model) use ($offers, $provider, $vendor) {
                    $offer = $offers[$model->id]->setRelation('aiModel', $model->setRelation('vendor', $vendor));

                    return [
                        ...$model->only('id', 'name', 'description'),
                        ...$this->prices($offer),
                        'capabilities' => $this->capabilities($offer, $provider),
                    ];
                }),
            ])
            ->values();
    }

    /**
     * Что модель умеет у провайдера и в каких пределах: {temperature: {min, max}, web_search: {max_uses, domains}, …}.
     * Поиск — только если провайдер умеет искать этой моделью (формат API есть), а не просто отмечен в БД.
     */
    private function capabilities(ProviderModel $offer, AiProvider $provider): object
    {
        $capabilities = $offer->capabilities
            ->reject(fn ($row) => $row->capability === Capability::WebSearch)
            ->mapWithKeys(fn ($row) => [$row->capability->value => (object) $row->limits()]);

        if ($search = $provider->webSearch($offer)) {
            $capabilities[Capability::WebSearch->value] = (object) Arr::only($search, ['max_uses', 'domains']);
        }

        return (object) $capabilities->all();
    }

    private function prices(ProviderModel $offer): array
    {
        return [
            'price_input' => $offer->price_input,
            'price_output' => $offer->price_output,
            'price_currency' => $offer->price_currency,
        ];
    }
}
