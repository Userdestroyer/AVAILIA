<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Лимиты, которые есть у любой модели, — колонками; null — неизвестно
        Schema::table('provider_models', function (Blueprint $table) {
            $table->unsignedInteger('context_length')->nullable()->after('upstream');
            $table->unsignedInteger('max_output_tokens')->nullable()->after('context_length');
        });

        // Необязательные возможности модели у провайдера (App\Enums\Capability), у каждой — свои ограничения.
        // У провайдера, а не у модели: одна и та же модель через разных агрегаторов умеет разное
        Schema::create('provider_model_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_model_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 32);
            // Ограничения возможности: {min, max} у температуры и т.п.; null — по умолчанию из enum
            $table->jsonb('settings')->nullable();
            $table->timestamps();

            $table->unique(['provider_model_id', 'capability']);
            // «Какие модели умеют X» — для фильтров и будущего фолбэка
            $table->index('capability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_model_capabilities');
        Schema::table('provider_models', function (Blueprint $table) {
            $table->dropColumn(['context_length', 'max_output_tokens']);
        });
    }
};
