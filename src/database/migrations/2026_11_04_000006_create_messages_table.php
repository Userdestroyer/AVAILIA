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
            // Себестоимость в USD по ценам модели на момент ответа.
            // В валюту юзера переводится при показе — по курсу на дату сообщения.
            $table->decimal('cost_usd', 16, 8)->default(0);
            $table->timestamps();

            $table->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
