<?php

namespace App\Services\Ai;

use App\Enums\Capability;
use App\Models\Provider;
use Illuminate\Support\Facades\Http;

/**
 * Каталог моделей Polza (GET /v1/models): у Polza десятки источников с разными ценами
 * и возможностями, поэтому расценки, лимиты и возможности подтягиваем из API, а не вбиваем руками.
 */
class PolzaCatalog
{
    /**
     * Обновляет подключённые модели Polza по их external_id (ничего не добавляет).
     * Если у предложения закреплён источник, а в каталоге только «лучший» — берём данные лучшего.
     *
     * @return int сколько предложений обновлено
     */
    public function sync(): int
    {
        $config = config('ai.providers.polza');
        $catalog = Http::withToken($config['key'])
            ->timeout(20)
            ->retry(2, 500)
            ->get(rtrim($config['url'], '/').'/models', ['type' => 'chat'])
            ->throw()
            ->json('data', []);

        $byId = collect($catalog)->keyBy('id');
        $updated = 0;

        foreach (Provider::where('slug', 'polza')->firstOrFail()->offerings as $offer) {
            $item = $byId[$offer->external_id] ?? null;
            $top = $item['top_provider'] ?? null;
            if (! $top) {
                continue;
            }

            $pricing = $top['pricing'] ?? [];
            $offer->update([
                'price_input' => $pricing['prompt_per_million'] ?? null,
                'price_output' => $pricing['completion_per_million'] ?? null,
                'price_cache_read' => $pricing['input_cache_read_per_million'] ?? null,
                'price_cache_write' => $pricing['input_cache_write_per_million'] ?? null,
                'price_currency' => $pricing['currency'] ?? 'RUB',
                'context_length' => $top['context_length'] ?? $item['context_length'] ?? null,
                'max_output_tokens' => $top['max_completion_tokens'] ?? $item['max_completion_tokens'] ?? null,
            ]);
            $offer->syncCapabilities(array_fill_keys(self::capabilities($item), null));
            $updated++;
        }

        return $updated;
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
