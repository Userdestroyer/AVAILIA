<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('type');
            // Идентификатор модели в API вендора, например gpt-5 или claude-sonnet-5-5
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('context_window')->nullable();
            // Цены в USD за 1M токенов
            $table->decimal('price_input', 12, 4)->default(0);
            $table->decimal('price_output', 12, 4)->default(0);
            $table->decimal('price_cache_read', 12, 4)->nullable();
            $table->decimal('price_cache_write', 12, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['vendor_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
