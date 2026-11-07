<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Провайдер — агрегатор, через который идут запросы: proxyapi, polza
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            // По slug выбирается класс провайдера и его адрес/ключ у гейтвея
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Модель у конкретного провайдера: её id там, источник и расценки.
        // Любая расценка может быть пустой — модель доступна, стоимость тогда от провайдера или неизвестна
        Schema::create('provider_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained()->cascadeOnDelete();
            // Id модели в API провайдера: anthropic/claude-haiku-5-5 (proxyapi), anthropic/claude-haiku-5.5 (polza)
            $table->string('external_id');
            // Источник внутри провайдера (у Polza: openai/flex, amazon-bedrock…); пусто — выбирает провайдер
            $table->string('upstream')->nullable();
            // ₽ (или price_currency) за 1M токенов
            $table->decimal('price_input', 14, 6)->nullable();
            $table->decimal('price_output', 14, 6)->nullable();
            $table->decimal('price_cache_read', 14, 6)->nullable();
            $table->decimal('price_cache_write', 14, 6)->nullable();
            // За один поиск в интернете
            $table->decimal('price_web_search', 10, 4)->nullable();
            $table->char('price_currency', 3)->default('RUB');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider_id', 'ai_model_id']);
        });

        // Настройки приложения из админки (активный провайдер и т.п.): ключ → JSON-значение
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('provider_models');
        Schema::dropIfExists('providers');
    }
};
