<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Скорость ответа: «узкие места ищем, а не угадываем»
        Schema::table('messages', function (Blueprint $table) {
            // Гейтвей: от подключения браузера к стриму до первого видимого токена (текст или поиск)
            $table->unsignedInteger('ttft_ms')->nullable()->after('search_log');
            // Браузер: от нажатия «Отправить» до первого токена на экране — то, что ощущает человек
            $table->unsignedInteger('client_ttft_ms')->nullable()->after('ttft_ms');
            // Гейтвей: генерация целиком
            $table->unsignedInteger('duration_ms')->nullable()->after('client_ttft_ms');
        });

        // Фоновые вызовы сервисной модели (название чата и т.п.): тоже стоят денег — учитываем
        Schema::create('service_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 32);
            $table->foreignId('provider_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 14, 8)->nullable();
            $table->char('cost_currency', 3)->default('RUB');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_calls');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['ttft_ms', 'client_ttft_ms', 'duration_ms']);
        });
    }
};
