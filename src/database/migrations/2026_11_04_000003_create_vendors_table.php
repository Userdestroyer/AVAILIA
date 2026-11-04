<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            // По slug Go-гейтвей выбирает адаптер API (openai, anthropic, google, xai)
            $table->string('slug')->unique();
            $table->string('name');
            // Название продукта для интерфейса: ChatGPT, Claude, Gemini, Grok
            $table->string('product')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
