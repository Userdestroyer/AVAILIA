<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Лимиты по трём сферам (docs/LIMITS.md): деньги в окнах «сессия + неделя» для текста и агентов,
 * баланс токенов для контента. Старые тарифы (гость, pro, unlimited) уходят — остаются Promo и Custom;
 * пользователи переводятся сами: админы — на Custom, остальные — на Promo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['daily_request_limit', 'monthly_token_limit', 'monthly_budget']);
        });
        Schema::table('plans', function (Blueprint $table) {
            // ₽ в месяц на текст и агентов, токенов в месяц на контент; null — без ограничений
            $table->decimal('text_monthly', 12, 2)->nullable()->after('currency');
            $table->decimal('agent_monthly', 12, 2)->nullable()->after('text_monthly');
            $table->unsignedInteger('content_tokens_monthly')->nullable()->after('agent_monthly');
            // Неделя делится на столько сессий; длина сессии
            $table->decimal('sessions_per_week', 4, 2)->default(4.5)->after('content_tokens_monthly');
            $table->unsignedSmallInteger('session_hours')->default(6)->after('sessions_per_week');
        });

        $now = now();
        DB::table('plans')->upsert([
            ['code' => 'promo', 'name' => 'Promo', 'description' => 'По умолчанию для всех', 'text_monthly' => 100, 'agent_monthly' => 100,
                'content_tokens_monthly' => 20, 'sessions_per_week' => 2, 'session_hours' => 6, 'is_public' => false, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'custom', 'name' => 'Custom', 'description' => 'Калибровка лимитов', 'text_monthly' => 2500, 'agent_monthly' => 1000,
                'content_tokens_monthly' => 500, 'sessions_per_week' => 4.5, 'session_hours' => 6, 'is_public' => false, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'description', 'text_monthly', 'agent_monthly', 'content_tokens_monthly', 'sessions_per_week', 'session_hours']);

        $promo = DB::table('plans')->where('code', 'promo')->value('id');
        $custom = DB::table('plans')->where('code', 'custom')->value('id');
        DB::table('users')->where('role', 'admin')->update(['plan_id' => $custom, 'plan_expires_at' => null]);
        DB::table('users')->where('role', '!=', 'admin')->update(['plan_id' => $promo, 'plan_expires_at' => null]);
        DB::table('plans')->whereNotIn('code', ['promo', 'custom'])->delete();

        Schema::table('users', function (Blueprint $table) {
            // Баланс токенов контента и когда было последнее ежемесячное начисление
            $table->unsignedBigInteger('content_tokens')->default(0)->after('plan_expires_at');
            $table->timestamp('tokens_granted_at')->nullable()->after('content_tokens');
        });

        // Журнал денежных трат — источник правды для лимитов, аудита и сверки
        Schema::create('usage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            // ₽ по курсу на дату траты
            $table->decimal('amount', 14, 6);
            // Стоимость была неизвестна — оценена по ценам вендора
            $table->boolean('estimated')->default(false);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'scope', 'created_at']);
        });

        // Открытые окна лимитов (сессия, неделя) с накопленной суммой — проверка без SUM
        Schema::create('usage_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            $table->string('kind', 8);
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->decimal('spent', 14, 6)->default(0);
            $table->timestamps();

            $table->index(['user_id', 'scope', 'kind', 'ends_at']);
        });

        // История баланса токенов: начисления, покупки, списания, возвраты — с остатком после
        Schema::create('token_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('reason', 16);
            $table->string('comment', 200)->nullable();
            // Кто начислил вручную
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_transactions');
        Schema::dropIfExists('usage_windows');
        Schema::dropIfExists('usage_entries');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['content_tokens', 'tokens_granted_at']);
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['text_monthly', 'agent_monthly', 'content_tokens_monthly', 'sessions_per_week', 'session_hours']);
            $table->unsignedInteger('daily_request_limit')->nullable();
            $table->unsignedBigInteger('monthly_token_limit')->nullable();
            $table->decimal('monthly_budget', 10, 2)->nullable();
        });
    }
};
