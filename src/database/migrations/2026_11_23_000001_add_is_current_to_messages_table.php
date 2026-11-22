<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Варианты ответа одной модели на один вопрос (регенерация): показывается и идёт в историю текущий
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_current')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('is_current');
        });
    }
};
