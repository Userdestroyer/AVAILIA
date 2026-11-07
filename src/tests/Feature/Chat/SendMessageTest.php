<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\Chat;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderModel;
use App\Services\Ai\ProviderRegistry;
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
        $jobs = $this->captureStreamJobs();
        $model = AiModel::where('slug', 'claude-sonnet-5-5')->first();

        $response = $this->actingAs($user = User::factory()->create())
            ->postJson('/text/messages', ['ai_model_ids' => [$model->id], 'content' => '  Привет  ', 'max_tokens' => 1024])
            ->assertCreated()
            ->assertJsonPath('user_message.content', 'Привет')
            ->assertJsonPath('answers.0.message.status', MessageStatus::Pending->value);

        $chat = $user->chats()->sole();
        $this->assertSame($response->json('chat.id'), $chat->id);
        $this->assertSame([MessageRole::User, MessageRole::Assistant], $chat->messages()->orderBy('id')->pluck('role')->all());

        [$key, $payload] = $jobs()[0];
        $this->assertSame('stream:'.$response->json('answers.0.stream_token'), $key);
        $this->assertSame('proxyapi', $payload['provider']);
        $this->assertSame($response->json('answers.0.message.id'), $payload['message_id']);
        $this->assertSame('anthropic/claude-sonnet-5-5', $payload['body']['model']);
        $this->assertTrue($payload['body']['stream']);
        $this->assertSame([['role' => 'user', 'content' => 'Привет']], $payload['body']['messages']);
        $this->assertStringNotContainsString('provider-secret-key', json_encode($payload));
    }

    #[Group('D1')]
    #[TestDox('Мульти-запрос: каждой модели свой ответ и стрим, в истории каждая видит свой прошлый ответ; температура — только тем, кто её принимает')]
    public function test_multi_request_gives_each_model_its_own_history(): void
    {
        $jobs = $this->captureStreamJobs();
        [$claude, $gemini] = [AiModel::where('slug', 'claude-haiku-5-5')->first(), AiModel::where('slug', 'gemini-4-argon')->first()];

        $user = User::factory()->create();
        $chat = $user->chats()->create();
        $question = $chat->messages()->create(['role' => MessageRole::User, 'content' => 'Вопрос']);
        foreach ([[$claude, 'от Claude'], [$gemini, 'от Gemini']] as [$model, $text]) {
            $chat->messages()->create([
                'parent_id' => $question->id, 'role' => MessageRole::Assistant, 'status' => MessageStatus::Done,
                'ai_model_id' => $model->id, 'content' => $text,
            ]);
        }

        $this->actingAs($user)
            ->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_ids' => [$gemini->id, $claude->id], 'content' => 'Ещё', 'temperature' => 0.4])
            ->assertCreated()
            ->assertJsonCount(2, 'answers');

        $bodies = collect($jobs())->mapWithKeys(fn ($job) => [$job[1]['body']['model'] => $job[1]['body']]);
        $this->assertSame(['Вопрос', 'от Gemini', 'Ещё'], array_column($bodies['google/gemini-4-argon']['messages'], 'content'));
        $this->assertSame(['Вопрос', 'от Claude', 'Ещё'], array_column($bodies['anthropic/claude-haiku-5-5']['messages'], 'content'));
        // Gemini температуру принимает, Claude — нет: ему она не уходит, запрос не падает
        $this->assertSame(0.4, $bodies['google/gemini-4-argon']['temperature']);
        $this->assertArrayNotHasKey('temperature', $bodies['anthropic/claude-haiku-5-5']);
        $this->assertSame(2, $chat->messages()->where('parent_id', '!=', $question->id)->whereNotNull('parent_id')->count());
    }

    #[Group('D1')]
    #[TestDox('Веб-поиск: лимит и домены доходят до запроса к модели, домены нормализуются, лимит больше 20 не пройдёт')]
    public function test_web_search_options_reach_the_model(): void
    {
        $jobs = $this->captureStreamJobs();
        $claude = AiModel::where('slug', 'claude-haiku-5-5')->first();
        $this->actingAs(User::factory()->create());

        $this->postJson('/text/messages', ['ai_model_ids' => [$claude->id], 'content' => 'Курс?', 'web_search' => true, 'max_web_searches' => 21])
            ->assertUnprocessable();

        $this->postJson('/text/messages', [
            'ai_model_ids' => [$claude->id],
            'content' => 'Курс?',
            'web_search' => true,
            'max_web_searches' => 2,
            'allowed_domains' => ['https://www.CBR.ru/currency', 'cbr.ru'],
        ])->assertCreated();

        $job = $jobs()[0][1];
        $this->assertSame(['messages', '/messages'], [$job['format'], $job['path']]);
        $this->assertSame(2, $job['body']['tools'][0]['max_uses']);
        $this->assertSame(['www.cbr.ru', 'cbr.ru'], $job['body']['tools'][0]['allowed_domains']);
    }

    #[Group('D1')]
    #[TestDox('Запрос уходит через активного провайдера с его id модели; отключённую у него модель не отправить')]
    public function test_request_goes_through_active_provider(): void
    {
        $jobs = $this->captureStreamJobs();
        $haiku = AiModel::where('slug', 'claude-haiku-5-5')->first();
        $polza = Provider::where('slug', 'polza')->first();
        app(ProviderRegistry::class)->activate($polza);
        $this->actingAs(User::factory()->create());

        $this->postJson('/text/messages', ['ai_model_ids' => [$haiku->id], 'content' => 'Привет'])->assertCreated();
        [$key, $job] = $jobs()[0];
        $this->assertSame(['polza', 'anthropic/claude-haiku-5.5'], [$job['provider'], $job['body']['model']]);
        $this->assertSame($polza->id, $haiku->fresh()->offerings()->where('provider_id', $polza->id)->value('provider_id'));

        ProviderModel::where('provider_id', $polza->id)->where('ai_model_id', $haiku->id)->update(['is_active' => false]);
        $this->postJson('/text/messages', ['ai_model_ids' => [$haiku->id], 'content' => 'Ещё'])->assertUnprocessable();
    }

    #[Group('D1')]
    #[TestDox('Нельзя ни открыть чужой чат, ни написать в него, ни удалить')]
    public function test_foreign_chat_is_not_found(): void
    {
        $chat = Chat::create(['user_id' => User::factory()->create()->id]);
        $model = AiModel::first();

        $this->actingAs(User::factory()->create());
        $this->get("/text/{$chat->id}")->assertNotFound();
        $this->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_ids' => [$model->id], 'content' => 'Привет'])
            ->assertNotFound();
        $this->deleteJson("/text/{$chat->id}")->assertNotFound();
        $this->assertSame(0, $chat->messages()->count());
        $this->assertNotSoftDeleted($chat);
    }

    #[Group('D2')]
    public function test_own_chat_is_soft_deleted_and_hidden(): void
    {
        $chat = ($user = User::factory()->create())->chats()->create(['title' => 'Старый']);

        $this->actingAs($user)->deleteJson("/text/{$chat->id}")->assertNoContent();

        $this->assertSoftDeleted($chat);
        $this->get("/text/{$chat->id}")->assertNotFound();
    }

    #[Group('D1')]
    #[TestDox('Сверх дневного лимита тарифа запрос не уходит к модели; мульти-запрос считается по числу моделей')]
    public function test_daily_plan_limit_blocks_requests(): void
    {
        Redis::shouldReceive('connection')->never();

        $user = User::factory()->create(['plan_id' => Plan::where('code', 'pro')->value('id')]);
        $user->plan->update(['daily_request_limit' => 2]);
        $chat = $user->chats()->create();
        $chat->messages()->create(['role' => MessageRole::Assistant, 'status' => MessageStatus::Done]);

        // Осталась 1 попытка, а моделей — 2
        $this->actingAs($user)
            ->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_ids' => AiModel::limit(2)->pluck('id')->all(), 'content' => 'Ещё'])
            ->assertTooManyRequests();
    }

    #[Group('D1')]
    #[TestDox('Удалённые ответы остаются в дневном лимите; чужое сообщение и пишущийся ответ удалить нельзя')]
    public function test_deleting_messages_keeps_limits_and_ownership(): void
    {
        $user = User::factory()->create(['plan_id' => Plan::where('code', 'pro')->value('id')]);
        $user->plan->update(['daily_request_limit' => 1]);
        $chat = $user->chats()->create();
        $question = $chat->messages()->create(['role' => MessageRole::User, 'content' => 'Вопрос']);
        $answer = $chat->messages()->create(['parent_id' => $question->id, 'role' => MessageRole::Assistant, 'status' => MessageStatus::Done]);
        $streaming = $chat->messages()->create(['role' => MessageRole::Assistant, 'status' => MessageStatus::Streaming]);

        $this->actingAs(User::factory()->create())->deleteJson("/text/messages/{$answer->id}")->assertNotFound();

        $this->actingAs($user);
        $this->deleteJson("/text/messages/{$streaming->id}")->assertConflict();
        $this->deleteJson("/text/messages/{$answer->id}")->assertNoContent();
        $this->assertSoftDeleted($answer);

        // Удалили ответ — а лимит на сегодня всё равно исчерпан
        Redis::shouldReceive('connection')->never();
        $this->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_ids' => [AiModel::first()->id], 'content' => 'Ещё'])
            ->assertTooManyRequests();
    }

    #[Group('D2')]
    public function test_deleted_question_and_its_answers_leave_model_history(): void
    {
        $jobs = $this->captureStreamJobs();
        $model = AiModel::where('slug', 'claude-haiku-5-5')->first();
        $user = User::factory()->create();
        $chat = $user->chats()->create();
        $question = $chat->messages()->create(['role' => MessageRole::User, 'content' => 'Забудь это']);
        $answer = $chat->messages()->create(['parent_id' => $question->id, 'role' => MessageRole::Assistant, 'status' => MessageStatus::Done, 'ai_model_id' => $model->id, 'content' => 'Ок']);
        $this->actingAs($user);

        $this->deleteJson("/text/messages/{$question->id}")->assertNoContent();
        $this->assertSoftDeleted($answer);

        $this->postJson('/text/messages', ['chat_id' => $chat->id, 'ai_model_ids' => [$model->id], 'content' => 'Новое'])->assertCreated();
        $this->assertSame([['role' => 'user', 'content' => 'Новое']], $jobs()[0][1]['body']['messages']);
    }

    /**
     * Подменяет Redis и возвращает функцию, отдающую список [ключ, разобранное задание].
     */
    private function captureStreamJobs(): \Closure
    {
        $captured = [];

        Redis::shouldReceive('connection')->with('gateway')->andReturnSelf();
        Redis::shouldReceive('setex')->withArgs(function ($key, $ttl, $payload) use (&$captured) {
            $captured[] = [$key, json_decode($payload, true)];

            return true;
        });

        return function () use (&$captured) {
            return $captured;
        };
    }
}
