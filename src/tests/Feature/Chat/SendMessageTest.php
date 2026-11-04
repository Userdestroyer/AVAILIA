<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SendMessageTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Сообщение создаёт чат и ответ в ожидании, а в Redis уходит стрим-задание без ключа провайдера')]
    public function test_message_starts_stream_job(): void
    {
        config(['ai.providers.proxyapi.key' => 'provider-secret-key']);
        $job = $this->captureStreamJob();
        $model = AiModel::where('slug', 'claude-sonnet-5-5')->first();

        $response = $this->actingAs($user = User::factory()->create())
            ->postJson('/text/messages', ['ai_model_id' => $model->id, 'content' => '  Привет  ', 'max_tokens' => 1024])
            ->assertCreated()
            ->assertJsonPath('user_message.content', 'Привет')
            ->assertJsonPath('assistant_message.status', MessageStatus::Pending->value);

        $chat = $user->chats()->sole();
        $this->assertSame($response->json('chat.id'), $chat->id);
        $this->assertSame([MessageRole::User, MessageRole::Assistant], $chat->messages()->orderBy('id')->pluck('role')->all());

        [$key, $payload] = $job();
        $this->assertSame('stream:'.$response->json('stream_token'), $key);
        $this->assertSame('proxyapi', $payload['provider']);
        $this->assertSame($response->json('assistant_message.id'), $payload['message_id']);
        $this->assertSame('anthropic/claude-sonnet-5-5', $payload['body']['model']);
        $this->assertTrue($payload['body']['stream']);
        $this->assertSame([['role' => 'user', 'content' => 'Привет']], $payload['body']['messages']);
        $this->assertStringNotContainsString('provider-secret-key', json_encode($payload));
    }

    #[Group('D1')]
    #[TestDox('Нельзя ни открыть чужой чат, ни написать в него')]
    public function test_foreign_chat_is_not_found(): void
    {
        $chat = Chat::create(['user_id' => User::factory()->create()->id]);
        $model = AiModel::first();

        $this->actingAs(User::factory()->create());
        $this->get("/text/{$chat->id}")->assertNotFound();
        $this->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_id' => $model->id, 'content' => 'Привет'])
            ->assertNotFound();
        $this->assertSame(0, $chat->messages()->count());
    }

    #[Group('D1')]
    #[TestDox('Сверх дневного лимита тарифа запрос не уходит к модели')]
    public function test_daily_plan_limit_blocks_requests(): void
    {
        Redis::shouldReceive('connection')->never();

        $user = User::factory()->create(['plan_id' => Plan::where('code', 'pro')->value('id')]);
        $user->plan->update(['daily_request_limit' => 1]);
        $chat = $user->chats()->create();
        $chat->messages()->create(['role' => MessageRole::Assistant, 'status' => MessageStatus::Done]);

        $this->actingAs($user)
            ->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_id' => AiModel::first()->id, 'content' => 'Ещё'])
            ->assertTooManyRequests();
    }

    /**
     * Подменяет Redis и возвращает функцию, отдающую ключ и разобранное задание.
     */
    private function captureStreamJob(): \Closure
    {
        $captured = null;

        Redis::shouldReceive('connection')->with('gateway')->andReturnSelf();
        Redis::shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $payload) use (&$captured) {
            $captured = [$key, json_decode($payload, true)];

            return true;
        });

        return function () use (&$captured) {
            return $captured;
        };
    }
}
