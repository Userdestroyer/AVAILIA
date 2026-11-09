<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiModelType;
use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AppSetting;
use App\Models\Provider;
use App\Models\ProviderModel;
use App\Models\Vendor;
use App\Services\Ai\PolzaCatalog;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\ServiceModel;
use App\Services\Stats\SpeedStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Админка: провайдер, через который идут запросы, и модели с расценками по провайдерам.
 * Фронт применяет изменения сразу, сюда они приходят фоном.
 */
class AdminController extends Controller
{
    public function __construct(private ProviderRegistry $providers) {}

    public function index(ServiceModel $service): Response
    {
        $providers = Provider::orderBy('sort')->withCount(['offerings' => fn ($query) => $query->where('is_active', true)])->get();

        return Inertia::render('Admin/Index', [
            'providers' => $providers->map(fn (Provider $provider) => [
                ...$provider->only('id', 'slug', 'name'),
                'models_count' => $provider->offerings_count,
                // Ключ задан в .env — иначе запросы через провайдера упадут
                'has_key' => filled(config("ai.providers.{$provider->slug}.key")),
            ]),
            'activeProvider' => $this->providers->activeSlug(),
            'serviceModel' => $service->model()?->id,
            'plans' => LimitsController::plans(),
            'users' => LimitsController::users(),
            // Статистика тяжелее остального — догружается после открытия страницы
            'speed' => Inertia::defer(fn () => (new SpeedStats)->summary()),
            'vendors' => Vendor::orderBy('sort')->get(['id', 'slug', 'name', 'product']),
            'capabilities' => array_map(fn (Capability $c) => ['value' => $c->value, 'label' => $c->label()], Capability::cases()),
            'models' => AiModel::with(['offerings.provider', 'offerings.capabilities'])->orderBy('vendor_id')->orderBy('sort')->get()->map(fn (AiModel $model) => [
                ...$model->only('id', 'vendor_id', 'slug', 'name', 'description', 'is_active'),
                'type' => $model->type,
                'offerings' => $model->offerings->mapWithKeys(fn (ProviderModel $offer) => [$offer->provider->slug => $this->offering($offer)]),
            ]),
        ]);
    }

    public function activate(Request $request): HttpResponse
    {
        $validated = $request->validate(['slug' => ['required', Rule::exists('providers', 'slug')]]);
        $this->providers->activate(Provider::where('slug', $validated['slug'])->firstOrFail());

        return response()->noContent();
    }

    public function serviceModel(Request $request): HttpResponse
    {
        $validated = $request->validate([
            'ai_model_id' => ['required', Rule::exists('ai_models', 'id')->where('is_active', true)->where('type', AiModelType::Text->value)],
        ]);
        AppSetting::put(ServiceModel::SETTING, (int) $validated['ai_model_id']);

        return response()->noContent();
    }

    public function storeModel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => ['required', Rule::exists('vendors', 'id')],
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9.\\-]*$/',
                Rule::unique('ai_models')->where('vendor_id', $request->integer('vendor_id'))],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        $model = AiModel::create([...$validated, 'type' => AiModelType::Text, 'sort' => 100]);

        return response()->json(['model' => [...$model->only('id', 'vendor_id', 'slug', 'name', 'description', 'is_active'), 'type' => $model->type, 'offerings' => (object) []]], 201);
    }

    public function updateModel(Request $request, AiModel $model): HttpResponse
    {
        $model->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->noContent();
    }

    /**
     * Подключить модель к провайдеру или поправить расценки, лимиты и возможности.
     * Любая цена и любой лимит могут быть пустыми; без capabilities набор возможностей не меняется.
     */
    public function upsertOffering(Request $request, Provider $provider, AiModel $model): JsonResponse
    {
        $price = ['nullable', 'numeric', 'min:0', 'max:10000000'];
        $validated = $request->validate([
            'external_id' => ['required', 'string', 'max:200'],
            'upstream' => ['nullable', 'string', 'max:100'],
            'price_input' => $price,
            'price_output' => $price,
            'price_cache_read' => $price,
            'price_cache_write' => $price,
            'price_web_search' => $price,
            'price_currency' => ['nullable', 'string', 'size:3'],
            'context_length' => ['nullable', 'integer', 'min:1'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => [Rule::enum(Capability::class)],
        ]);

        $offer = ProviderModel::updateOrCreate(
            ['provider_id' => $provider->id, 'ai_model_id' => $model->id],
            [...Arr::except($validated, 'capabilities'), 'price_currency' => strtoupper($validated['price_currency'] ?? 'RUB')],
        );

        if (array_key_exists('capabilities', $validated)) {
            $offer->syncCapabilities(array_fill_keys(array_unique($validated['capabilities']), null));
        }

        return response()->json(['offering' => $this->offering($offer)]);
    }

    public function destroyOffering(Provider $provider, AiModel $model): HttpResponse
    {
        ProviderModel::where('provider_id', $provider->id)->where('ai_model_id', $model->id)->delete();

        return response()->noContent();
    }

    public function syncPolza(PolzaCatalog $catalog): JsonResponse
    {
        try {
            return response()->json(['updated' => $catalog->sync()]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Каталог Polza недоступен: '.$e->getMessage()], 502);
        }
    }

    private function offering(ProviderModel $offer): array
    {
        return [
            ...$offer->only(
                'external_id', 'upstream', 'context_length', 'max_output_tokens',
                'price_input', 'price_output', 'price_cache_read', 'price_cache_write', 'price_web_search', 'price_currency', 'is_active',
            ),
            'capabilities' => $offer->capabilities->map(fn ($row) => $row->capability->value)->values(),
        ];
    }
}
