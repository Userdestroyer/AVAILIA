<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Картриджи контекста: «Работа», «Здоровье»… — подключаются в системный промпт, не копируются
        Schema::create('context_plates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            // Текущее содержимое — для быстрой сборки промпта; история — в context_plate_versions
            $table->text('content');
            // Подключается в каждый новый чат сам
            $table->boolean('is_pinned')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'sort']);
        });

        // История как в git: только дописывается; откат — новая версия со старым содержимым
        Schema::create('context_plate_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('context_plate_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            // Кто автор: человек или модель (диффы — следующий шаг)
            $table->string('author', 16)->default('user');
            // Из какого сообщения родилась правка модели — «откуда ты это знаешь?»
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Теги — одна система для картриджей, а дальше файлов, чатов и проектов. У каждого юзера свои.
        // Привязка — своя таблица на каждый тип (context_plate_tag, дальше chat_tag, file_tag…): типизированные
        // ключи (у чатов UUID, у остальных числа) и каскадное удаление, чего полиморфная связь не даёт
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            // Без регистра и лишних пробелов: «Работа» и «работа » — один тег
            $table->string('slug', 40);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });

        Schema::create('context_plate_tag', function (Blueprint $table) {
            $table->foreignId('context_plate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['context_plate_id', 'tag_id']);
            $table->index('tag_id');
        });

        // Какие версии каких картриджей ушли с вопросом в модель — провенанс и восстановление выбора в чате
        Schema::table('messages', function (Blueprint $table) {
            $table->json('context')->nullable()->after('search_log');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('context');
        });
        Schema::dropIfExists('context_plate_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('context_plate_versions');
        Schema::dropIfExists('context_plates');
    }
};
