<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ответ знает, на какой вопрос отвечает: в мульти-запросе на один вопрос отвечают несколько моделей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('chat_id')->constrained('messages')->cascadeOnDelete();
        });

        // Уже существующие ответы — к ближайшему предыдущему вопросу в том же чате
        DB::statement(<<<'SQL'
            UPDATE messages AS answer
            SET parent_id = (
                SELECT question.id FROM messages AS question
                WHERE question.chat_id = answer.chat_id AND question.role = 'user' AND question.id < answer.id
                ORDER BY question.id DESC
                LIMIT 1
            )
            WHERE answer.role = 'assistant'
        SQL);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
