<?php

namespace App\Services\Ai;

use App\Enums\AiModelType;
use App\Enums\Capability;
use App\Models\AiModel;
use App\Models\Provider;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Каталог моделей Polza (GET /v1/models): у Polza десятки источников с разными ценами
 * и возможностями, поэтому расценки, лимиты и возможности подтягиваем из API, а не вбиваем руками.
 */
class PolzaCatalog
{
    /** @var list<array<string, mixed>>|null */
    private ?array $catalog = null;

    /**
     * Обновляет подключённые модели Polza по их external_id (ничего не добавляет).
     * Если у предложения закреплён источник, а в каталоге только «лучший» — берём данные лучшего.
     *
     * @return int сколько предложений обновлено
     */
    public function sync(): int
    {
        $byId = collect($this->catalog())->keyBy('id');
        $updated = 0;

        foreach ($this->provider()->offerings as $offer) {
            $item = $byId[$offer->external_id] ?? null;
            if (! isset($item['top_provider'])) {
                continue;
            }

            $offer->update(self::offerAttributes($item));
            $offer->syncCapabilities(array_fill_keys(self::capabilities($item), null));
            $updated++;
        }

        return $updated;
    }

    /**
     * Новые модели из каталога: у каждого вендора из config ai.import.vendors — самые свежие $perVendor
     * (по дате появления у Polza), которых у нас ещё нет. Создаются скрытыми — включает админ;
     * цены, лимиты и возможности — сразу из каталога.
     *
     * @return list<array{vendor: string, id: string, name: string}> что добавлено
     */
    public function import(int $perVendor): array
    {
        $catalog = collect($this->catalog())->filter(fn (array $item) => isset($item['top_provider']));
        $provider = $this->provider();
        $connected = $provider->offerings()->pluck('external_id')->flip();
        $added = [];

        foreach (config('ai.import.vendors') as $prefix => $slug) {
            $vendor = Vendor::where('slug', $slug)->first();
            $fresh = $catalog
                ->filter(fn (array $item) => str_starts_with($item['id'], "{$prefix}/"))
                ->sortByDesc(fn (array $item) => $item['created'] ?? 0)
                ->take($perVendor);

            foreach ($fresh as $item) {
                if (! $vendor || $connected->has($item['id'])) {
                    continue;
                }

                DB::transaction(function () use ($vendor, $provider, $item) {
                    $model = AiModel::firstOrCreate(
                        ['vendor_id' => $vendor->id, 'slug' => Str::after($item['id'], '/')],
                        [
                            'type' => AiModelType::Text,
                            // «Anthropic: Claude Haiku 5.5» → «Claude Haiku 5.5»
                            'name' => Str::contains($item['name'] ?? '', ': ') ? Str::after($item['name'], ': ') : ($item['name'] ?? $item['id']),
                            'is_active' => false,
                            'sort' => (int) $vendor->models()->max('sort') + 1,
                        ],
                    );
                    $offer = $model->offerings()->create([
                        'provider_id' => $provider->id,
                        'external_id' => $item['id'],
                        'price_web_search' => config('ai.providers.polza.web_search_price'),
                        ...self::offerAttributes($item),
                    ]);
                    $offer->syncCapabilities(array_fill_keys(self::capabilities($item), null));
                });
                $added[] = ['vendor' => $slug, 'id' => $item['id'], 'name' => $item['name'] ?? $item['id']];
            }
        }

        return $added;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $config = config('ai.providers.polza');

        // Импорт и обновление цен в одной команде — один запрос к каталогу
        return $this->catalog ??= Http::withToken($config['key'])
            ->timeout(20)
            ->retry(2, 500)
            ->get(rtrim($config['url'], '/').'/models', ['type' => 'chat'])
            ->throw()
            ->json('data', []);
    }

    private function provider(): Provider
    {
        return Provider::where('slug', 'polza')->firstOrFail();
    }

    /**
     * Цены и лимиты лучшего источника.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function offerAttributes(array $item): array
    {
        $top = $item['top_provider'];
        $pricing = $top['pricing'] ?? [];

        return [
            'price_input' => $pricing['prompt_per_million'] ?? null,
            'price_output' => $pricing['completion_per_million'] ?? null,
            'price_cache_read' => $pricing['input_cache_read_per_million'] ?? null,
            'price_cache_write' => $pricing['input_cache_write_per_million'] ?? null,
            'price_currency' => $pricing['currency'] ?? 'RUB',
            'context_length' => $top['context_length'] ?? $item['context_length'] ?? null,
            'max_output_tokens' => $top['max_completion_tokens'] ?? $item['max_completion_tokens'] ?? null,
        ];
    }

    /**
     * Возможности модели по каталогу: параметры, которые принимает лучший источник, и что модель берёт на вход.
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    public static function capabilities(array $item): array
    {
        $parameters = $item['top_provider']['supported_parameters'] ?? [];
        $inputs = $item['architecture']['input_modalities'] ?? [];

        return array_values(array_filter([
            in_array('temperature', $parameters, true) ? Capability::Temperature->value : null,
            array_intersect(['reasoning', 'reasoning_effort'], $parameters) ? Capability::Reasoning->value : null,
            in_array('image', $inputs, true) ? Capability::Images->value : null,
            in_array('file', $inputs, true) ? Capability::Files->value : null,
            // Polza ищет сама (polza:web_search) с любой моделью: умеющей вызывать функции — сколько
            // та решит, остальным — один поиск до вызова
            Capability::WebSearch->value,
        ]));
    }
}
