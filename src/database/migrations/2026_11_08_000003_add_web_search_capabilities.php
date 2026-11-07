<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Поиск в интернете переехал из кода провайдеров в возможности моделей (web_search).
 * Уже подключённым моделям — те же правила, что в AiModelSeeder: у Polza — всем со всеми
 * ограничениями, у proxyapi — Claude (лимит и домены), GPT (только домены), Gemini (только лимит).
 */
return new class extends Migration
{
    private const PROXYAPI = [
        'anthropic' => ['max_uses' => true, 'domains' => true],
        'openai' => ['max_uses' => false, 'domains' => true],
        'google' => ['max_uses' => true, 'domains' => false],
    ];

    public function up(): void
    {
        $offers = DB::table('provider_models')
            ->join('providers', 'providers.id', '=', 'provider_models.provider_id')
            ->join('ai_models', 'ai_models.id', '=', 'provider_models.ai_model_id')
            ->join('vendors', 'vendors.id', '=', 'ai_models.vendor_id')
            ->get(['provider_models.id', 'providers.slug as provider', 'vendors.slug as vendor']);

        $rows = $offers->map(function ($offer) {
            $settings = $offer->provider === 'polza'
                ? ['max_uses' => true, 'domains' => true]
                : (self::PROXYAPI[$offer->vendor] ?? null);

            return $settings ? [
                'provider_model_id' => $offer->id,
                'capability' => 'web_search',
                'settings' => json_encode($settings),
                'created_at' => now(),
                'updated_at' => now(),
            ] : null;
        })->filter()->values()->all();

        DB::table('provider_model_capabilities')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('provider_model_capabilities')->where('capability', 'web_search')->delete();
    }
};
