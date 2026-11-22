<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Чат текста или картинок: те же таблицы, разный режим
        Schema::table('chats', function (Blueprint $table) {
            $table->string('mode', 16)->default('text');
        });

        // Генерация медиа: id задания у провайдера, параметры, удержанные токены
        Schema::table('messages', function (Blueprint $table) {
            $table->json('generation')->nullable();
        });

        // Модели медиа: схема параметров (пропорции, разрешение, референсы) и прайс из каталога —
        // цена тут не «за токен», а за запрос или по ступеням разрешения
        Schema::table('provider_models', function (Blueprint $table) {
            $table->json('parameters')->nullable();
            $table->json('pricing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn('mode'));
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('generation'));
        Schema::table('provider_models', fn (Blueprint $table) => $table->dropColumn(['parameters', 'pricing']));
    }
};
