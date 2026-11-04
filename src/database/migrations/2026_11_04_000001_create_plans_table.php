<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2)->nullable();
            $table->char('currency', 3)->default('RUB');
            // Лимиты: null — без ограничений
            $table->unsignedInteger('daily_request_limit')->nullable();
            $table->unsignedBigInteger('monthly_token_limit')->nullable();
            // Бюджет себестоимости в месяц, в валюте тарифа
            $table->decimal('monthly_budget', 10, 2)->nullable();
            // Непубличные тарифы (promo) не показываются в списке, выдаются вручную
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
