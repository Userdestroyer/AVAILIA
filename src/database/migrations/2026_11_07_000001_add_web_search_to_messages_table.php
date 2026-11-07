<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Сколько поисков оплачено — входит в себестоимость
            $table->unsignedSmallInteger('web_searches')->default(0)->after('cache_write_tokens');
            // Журнал поисков для пузырьков в интерфейсе: [{id, status, query}]
            $table->json('search_log')->nullable()->after('web_searches');
            // Какой провайдер ответил — для учёта и будущего фолбэка между провайдерами
            $table->foreignId('provider_id')->nullable()->after('ai_model_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn(['web_searches', 'search_log']);
        });
    }
};
