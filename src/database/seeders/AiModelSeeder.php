<?php

namespace Database\Seeders;

use App\Enums\AiModelType;
use App\Models\AiModel;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class AiModelSeeder extends Seeder
{
    /**
     * Текстовые модели и цены proxyapi.ru/models (₽ за 1M токенов, с НДС) на 2026-11-04.
     * Цены кэша proxyapi не публикует — null, считаются по цене ввода.
     * slug — id модели у вендора, без префикса агрегатора (его добавляет провайдер).
     * Сверено с GET /v1/models proxyapi: у Anthropic версии через дефис (claude-opus-5-5).
     */
    private const MODELS = [
        'anthropic' => [
            ['slug' => 'claude-fable-5-1', 'name' => 'Claude Fable 5.1', 'description' => 'Самая мощная модель Anthropic', 'price_input' => 1580, 'price_output' => 7900],
            ['slug' => 'claude-opus-5-5', 'name' => 'Claude Opus 5.5', 'description' => 'Сложные задачи и код', 'price_input' => 840, 'price_output' => 4200],
            ['slug' => 'claude-sonnet-5-5', 'name' => 'Claude Sonnet 5.5', 'description' => 'Баланс скорости и качества', 'price_input' => 500, 'price_output' => 2500],
            ['slug' => 'claude-haiku-5-5', 'name' => 'Claude Haiku 5.5', 'description' => 'Быстрая и дешёвая', 'price_input' => 30, 'price_output' => 150],
        ],
        'google' => [
            ['slug' => 'gemini-4-argon', 'name' => 'Gemini 4 Argon', 'description' => 'Флагман Google', 'price_input' => 1220, 'price_output' => 6100],
            ['slug' => 'gemini-3.8-flash', 'name' => 'Gemini 3.8 Flash', 'description' => 'Быстрая универсальная', 'price_input' => 455, 'price_output' => 2275],
            ['slug' => 'gemini-3.5-flash-lite', 'name' => 'Gemini 3.5 Flash-Lite', 'description' => 'Дешёвая и быстрая', 'price_input' => 91, 'price_output' => 758],
        ],
        'xai' => [
            ['slug' => 'grok-4.7', 'name' => 'Grok 4.7', 'description' => 'Флагман xAI', 'price_input' => 273.68, 'price_output' => 800],
            ['slug' => 'grok-4.20', 'name' => 'Grok 4.20', 'description' => 'Дешевле, предыдущее поколение', 'price_input' => 168.42, 'price_output' => 336.84],
        ],
        'openai' => [
            ['slug' => 'gpt-6-astra', 'name' => 'GPT-6 Astra', 'description' => 'Самая мощная модель OpenAI', 'price_input' => 1580, 'price_output' => 7900],
            ['slug' => 'gpt-6.1-sol', 'name' => 'GPT-6.1 Sol', 'description' => 'Баланс скорости и качества', 'price_input' => 420, 'price_output' => 2100],
            ['slug' => 'gpt-6.1-terra', 'name' => 'GPT-6.1 Terra', 'description' => 'Быстрая универсальная', 'price_input' => 260, 'price_output' => 1300],
            ['slug' => 'gpt-6-luna', 'name' => 'GPT-6 Luna', 'description' => 'Дешёвая и быстрая', 'price_input' => 30, 'price_output' => 150],
        ],
    ];

    public function run(): void
    {
        $vendors = Vendor::pluck('id', 'slug');

        foreach (self::MODELS as $vendorSlug => $models) {
            foreach ($models as $sort => $model) {
                AiModel::updateOrCreate(
                    ['vendor_id' => $vendors[$vendorSlug], 'slug' => $model['slug']],
                    $model + ['type' => AiModelType::Text, 'price_currency' => 'RUB', 'sort' => $sort],
                );
            }
        }
    }
}
