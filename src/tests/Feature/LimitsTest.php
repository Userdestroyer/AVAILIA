<?php

namespace Tests\Feature;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\PlanCode;
use App\Enums\UserRole;
use App\Enums\UsageScope;
use App\Models\AiModel;
use App\Models\Plan;
use App\Models\UsageWindow;
use App\Models\User;
use App\Services\Limits\TokenWallet;
use App\Services\Limits\UsageMeter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class LimitsTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Траты копятся в окнах сессии и недели; исчерпанная сессия — 429 со временем сброса, после неё — новая сессия, неделя копит дальше; удаление трату не возвращает')]
    public function test_text_spend_fills_session_and_week_windows(): void
    {
        $this->freezeTime();
        // Неделя — 100 ₽, сессия — 50 ₽ (две в неделю), 6 часов
        Plan::byCode(PlanCode::Promo)->update(['text_monthly' => 434.5, 'sessions_per_week' => 2, 'session_hours' => 6]);
        $user = User::factory()->create();
        $chat = $user->chats()->create();
        $answer = $chat->messages()->create(['role' => MessageRole::Assistant, 'status' => MessageStatus::Done]);
        $meter = app(UsageMeter::class);
        $send = fn () => $this->actingAs($user)->postJson('/text/messages', ['ai_model_ids' => [AiModel::first()->id], 'content' => 'Ещё']);

        $meter->charge($user, UsageScope::Text, 30, $answer);
        $meter->charge($user, UsageScope::Text, 25, $answer);
        Redis::shouldReceive('connection')->never();
        $send()->assertTooManyRequests()
            ->assertJsonPath('limit.kind', UsageWindow::SESSION)
            ->assertJsonPath('limit.resets_at', now()->addHours(6)->toIso8601String());

        // Удалённый ответ трату не возвращает
        $this->actingAs($user)->deleteJson("/text/messages/{$answer->id}")->assertNoContent();
        $this->assertNotNull($meter->check($user, UsageScope::Text));

        // Сессия кончилась — новая; неделя помнит 55 ₽ и упирается в 100
        $this->travel(6 * 60 + 1)->minutes();
        $this->assertNull($meter->check($user, UsageScope::Text));
        $meter->charge($user, UsageScope::Text, 45);
        $this->assertSame(UsageWindow::WEEK, $meter->check($user, UsageScope::Text)['kind']);

        $summary = $meter->summary($user)['text'];
        $this->assertSame([45.0, 100.0], [$summary['session']['spent'], $summary['week']['spent']]);
        $this->assertSame(3, $user->usageEntries()->count());
    }

    #[Group('D1')]
    #[TestDox('Токены контента: по тарифу раз в месяц (неиспользованные копятся), вручную — только админ, в том числе себе')]
    public function test_content_tokens_monthly_and_admin_grants(): void
    {
        Plan::byCode(PlanCode::Promo)->update(['content_tokens_monthly' => 20]);
        $user = User::factory()->create();
        $wallet = app(TokenWallet::class);

        $this->artisan('limits:grant-tokens')->assertSuccessful();
        $this->artisan('limits:grant-tokens')->assertSuccessful();
        $this->assertSame(20, $user->fresh()->content_tokens);
        $this->travel(1)->months();
        $this->assertTrue($wallet->grantMonthlyIfDue($user->fresh()));
        $this->assertSame(40, $user->fresh()->content_tokens);

        $this->actingAs($user)->postJson("/admin/users/{$user->id}/tokens", ['amount' => 1000])->assertForbidden();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->postJson("/admin/users/{$admin->id}/tokens", ['amount' => 500, 'comment' => 'калибровка'])
            ->assertOk()
            ->assertJsonPath('content_tokens', 500);
        $this->assertDatabaseHas('token_transactions', ['user_id' => $admin->id, 'amount' => 500, 'balance_after' => 500, 'reason' => 'admin', 'actor_id' => $admin->id]);
    }
}
