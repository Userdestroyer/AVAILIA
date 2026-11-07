<?php

namespace Database\Seeders;

use App\Enums\AiModelType;
use App\Enums\Capability;
use App\Models\AiModel;
use App\Models\Provider;
use App\Models\ProviderModel;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class AiModelSeeder extends Seeder
{
    /**
     * Модели и где они доступны. Цены — ₽ за 1M токенов (ввод, вывод, чтение кэша, запись кэша)
     * и ₽ за один поиск; null — неизвестно (кэш тогда считается по цене ввода).
     *
     * proxyapi — proxyapi.ru/models на 2026-11-04 (с НДС), кэш не публикует; поиск 1 ₽, у Gemini 1,5 ₽.
     *   ID сверены с GET /v1/models: у Anthropic версии через дефис (claude-opus-5-5).
     * polza — GET /v1/models, top_provider.pricing на 2026-11-07 (docs/polza/models.json);
     *   у Anthropic версии через точку (claude-opus-5.5); поиск — polza:web_search, 0,50 ₽.
     *
     * Лимиты (контекст, максимум ответа) и возможности — из того же каталога Polza; у proxyapi
     * каталога возможностей нет, ставим те же. Температуру принимают только Gemini и Grok:
     * рассуждающие Claude и GPT её не берут.
     */
    private const CAPABILITIES = [
        'anthropic' => [Capability::Reasoning, Capability::Images, Capability::Files],
        'google' => [Capability::Temperature, Capability::Reasoning, Capability::Images, Capability::Files],
        'xai' => [Capability::Temperature, Capability::Reasoning, Capability::Images, Capability::Files],
        'openai' => [Capability::Reasoning, Capability::Images, Capability::Files],
    ];

    private const MODELS = [
        'anthropic' => [
            ['claude-fable-5-1', 'Claude Fable 5.1', 'Самая мощная модель Anthropic', 1000000, null, [
                'proxyapi' => ['anthropic/claude-fable-5-1', 1580, 7900, null, null, 1],
                'polza' => ['anthropic/claude-fable-5.1', 949.095636, 4745.47818, 23.727391, 1186.369545, 0.5],
            ]],
            ['claude-opus-5-5', 'Claude Opus 5.5', 'Сложные задачи и код', 1000000, null, [
                'proxyapi' => ['anthropic/claude-opus-5-5', 840, 4200, null, null, 1],
                'polza' => ['anthropic/claude-opus-5.5', 379.638254, 1898.191272, 18.981913, 474.547818, 0.5],
            ]],
            ['claude-sonnet-5-5', 'Claude Sonnet 5.5', 'Баланс скорости и качества', 1000000, null, [
                'proxyapi' => ['anthropic/claude-sonnet-5-5', 500, 2500, null, null, 1],
                'polza' => ['anthropic/claude-sonnet-5.5', 189.819127, 949.095636, 9.490956, 237.273909, 0.5],
            ]],
            ['claude-haiku-5-5', 'Claude Haiku 5.5', 'Быстрая и дешёвая', 1000000, 128000, [
                'proxyapi' => ['anthropic/claude-haiku-5-5', 30, 150, null, null, 1],
                'polza' => ['anthropic/claude-haiku-5.5', 11.8639, 59.3195, 1.18639, 14.829875, 0.5],
            ]],
        ],
        'google' => [
            ['gemini-4-argon', 'Gemini 4 Argon', 'Флагман Google', 1048576, 65536, [
                'proxyapi' => ['google/gemini-4-argon', 1220, 6100, null, null, 1.5],
                'polza' => ['google/gemini-4-argon', 118.643091, 593.215455, null, null, 0.5],
            ]],
            ['gemini-3.8-flash', 'Gemini 3.8 Flash', 'Быстрая универсальная', 1048576, 65536, [
                'proxyapi' => ['google/gemini-3.8-flash', 455, 2275, null, null, 1.5],
                'polza' => ['google/gemini-3.8-flash', 44.491159, 222.455796, null, null, 0.5],
            ]],
            ['gemini-3.5-flash-lite', 'Gemini 3.5 Flash-Lite', 'Дешёвая и быстрая', 1048576, 65536, [
                'proxyapi' => ['google/gemini-3.5-flash-lite', 91, 758, null, null, 1.5],
                'polza' => ['google/gemini-3.5-flash-lite', 17.796464, 148.303864, null, null, 0.5],
            ]],
        ],
        'xai' => [
            ['grok-4.7', 'Grok 4.7', 'Флагман xAI', 500000, 450000, [
                'proxyapi' => ['x-ai/grok-4.7', 273.68, 800, null, null, null],
                'polza' => ['x-ai/grok-4.7', 232.3688, 697.1064, 58.0922, null, 0.5],
            ]],
            ['grok-4.20', 'Grok 4.20', 'Дешевле, предыдущее поколение', 2000000, 1800000, [
                'proxyapi' => ['x-ai/grok-4.20', 168.42, 336.84, null, null, null],
                'polza' => ['x-ai/grok-4.20', 145.2305, 290.461, 23.23688, null, 0.5],
            ]],
        ],
        'openai' => [
            ['gpt-6-astra', 'GPT-6 Astra', 'Самая мощная модель OpenAI', 1050000, 128000, [
                'proxyapi' => ['openai/gpt-6-astra', 1580, 7900, null, null, 1],
                'polza' => ['openai/gpt-6-astra', 593.195, 2965.975, 59.3195, 741.49375, 0.5],
            ]],
            ['gpt-6.1-sol', 'GPT-6.1 Sol', 'Баланс скорости и качества', 1050000, 128000, [
                'proxyapi' => ['openai/gpt-6.1-sol', 420, 2100, null, null, 1],
                'polza' => ['openai/gpt-6.1-sol', 118.639, 593.195, 5.93195, 148.29875, 0.5],
            ]],
            ['gpt-6.1-terra', 'GPT-6.1 Terra', 'Быстрая универсальная', 1050000, 128000, [
                'proxyapi' => ['openai/gpt-6.1-terra', 260, 1300, null, null, 1],
                'polza' => ['openai/gpt-6.1-terra', 58.0922, 290.461, 2.90461, 72.61525, 0.5],
            ]],
            ['gpt-6-luna', 'GPT-6 Luna', 'Дешёвая и быстрая', 1050000, 128000, [
                'proxyapi' => ['openai/gpt-6-luna', 30, 150, null, null, 1],
                'polza' => ['openai/gpt-6-luna', 5.93195, 29.65975, 0.593195, 7.414938, 0.5],
            ]],
        ],
    ];

    public function run(): void
    {
        $vendors = Vendor::pluck('id', 'slug');
        $providers = Provider::pluck('id', 'slug');

        foreach (self::MODELS as $vendorSlug => $models) {
            foreach ($models as $sort => [$slug, $name, $description, $context, $maxOutput, $offers]) {
                $model = AiModel::updateOrCreate(
                    ['vendor_id' => $vendors[$vendorSlug], 'slug' => $slug],
                    ['name' => $name, 'description' => $description, 'type' => AiModelType::Text, 'sort' => $sort],
                );

                foreach ($offers as $providerSlug => [$externalId, $input, $output, $cacheRead, $cacheWrite, $webSearch]) {
                    $offer = ProviderModel::updateOrCreate(
                        ['provider_id' => $providers[$providerSlug], 'ai_model_id' => $model->id],
                        [
                            'external_id' => $externalId,
                            'context_length' => $context,
                            'max_output_tokens' => $maxOutput,
                            'price_input' => $input,
                            'price_output' => $output,
                            'price_cache_read' => $cacheRead,
                            'price_cache_write' => $cacheWrite,
                            'price_web_search' => $webSearch,
                            'price_currency' => 'RUB',
                        ],
                    );
                    $offer->syncCapabilities(array_fill_keys(array_map(fn (Capability $c) => $c->value, self::CAPABILITIES[$vendorSlug]), null));
                }
            }
        }
    }
}
