<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('chat_id')->constrained()->cascadeOnDelete();
            // Чем отвечали (у сообщений юзера — null)
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->string('status')->default('done');
            $table->longText('content')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            // Себестоимость на момент ответа: от провайдера (если сообщил) или по расценкам.
            // null — неизвестна (у модели нет расценок). В валюту юзера — по курсу на дату сообщения.
            $table->decimal('cost', 16, 8)->nullable();
            $table->char('cost_currency', 3)->default('RUB');
            $table->timestamps();

            $table->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
