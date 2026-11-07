<?php

namespace App\Http\Controllers;

use App\Enums\AiModelType;
use App\Http\Resources\MessageResource;
use App\Models\Chat;
use App\Models\ProviderModel;
use App\Models\Vendor;
use App\Services\Ai\ProviderRegistry;
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

        return Inertia::render('Text/Index', [
            'vendors' => $this->vendors(),
            'chat' => $chat?->only('id', 'title', 'ai_model_id'),
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
            ->map(function (Vendor $vendor) use ($offers, $provider) {
                $first = $offers[$vendor->models->first()->id]->setRelation('aiModel', $vendor->models->first()->setRelation('vendor', $vendor));
                $search = $provider->webSearch($first);

                return [
                    'slug' => $vendor->slug,
                    'product' => $vendor->product ?? $vendor->name,
                    // Что вендор умеет в поиске у текущего провайдера: {max_uses, domains} или null
                    'web_search' => $search ? ['max_uses' => $search['max_uses'], 'domains' => $search['domains']] : null,
                    'models' => $vendor->models->map(fn ($model) => [
                        ...$model->only('id', 'name', 'description'),
                        ...$this->prices($offers[$model->id]),
                        // Что модель умеет у провайдера и в каких пределах: {temperature: {min, max}, reasoning: {}, …}
                        'capabilities' => (object) $offers[$model->id]->capabilities
                            ->mapWithKeys(fn ($row) => [$row->capability->value => (object) $row->limits()])
                            ->all(),
                    ]),
                ];
            })
            ->values();
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
