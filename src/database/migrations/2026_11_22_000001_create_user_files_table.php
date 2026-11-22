<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Файловое поле юзера: загруженное им и сгенерированное моделями. Ключ — UUID: адреса файлов не перебрать
        Schema::create('user_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            // Дедупликация — только внутри одного юзера
            $table->char('sha256', 64);
            // Путь на диске — с владельцем в пути; отдаётся только через проверку прав
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sha256']);
            $table->index(['user_id', 'source', 'created_at']);
        });

        // Что ушло в модель вместе с вопросом
        Schema::create('message_files', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort')->default(0);

            $table->primary(['message_id', 'user_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_files');
        Schema::dropIfExists('user_files');
    }
};
