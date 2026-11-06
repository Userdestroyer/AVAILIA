<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class GatewayCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Без секрета гейтвея — 403; с секретом ответ сохраняется с себестоимостью по usage, повтор ничего не меняет')]
    public function test_gateway_completes_answer_with_cost(): void
    {
        config(['ai.gateway.secret' => 'gateway-secret']);

        // 1580 ₽ / 7900 ₽ за 1M; кэш без своей цены — по цене ввода
        $model = AiModel::where('slug', 'claude-fable-5-1')->first();
        $answer = User::factory()->create()->chats()->create()->messages()->create([
            'role' => MessageRole::Assistant, 'status' => MessageStatus::Pending, 'ai_model_id' => $model->id,
        ]);
        $url = "/internal/gateway/messages/{$answer->id}/complete";
        $result = [
            'status' => 'done',
            'content' => 'Ответ',
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 350, 'prompt_tokens_details' => ['cached_tokens' => 200]],
        ];

        $this->postJson($url, $result, ['X-Gateway-Secret' => 'wrong'])->assertForbidden();
        $this->assertSame(MessageStatus::Pending, $answer->fresh()->status);

        $this->postJson($url, $result, ['X-Gateway-Secret' => 'gateway-secret'])
            ->assertOk()
            ->assertJsonPath('message.status', 'done')
            ->assertJsonPath('message.cost', ['amount' => '4.66100000', 'currency' => 'RUB']);

        $answer->refresh();
        $this->assertSame([1000, 350, 200], [$answer->input_tokens, $answer->output_tokens, $answer->cache_read_tokens]);
        $this->assertSame(['4.66100000', 'RUB'], [$answer->cost, $answer->cost_currency]);

        $this->postJson($url, ['status' => 'failed', 'error' => 'late'], ['X-Gateway-Secret' => 'gateway-secret'])->assertOk();
        $this->assertSame(MessageStatus::Done, $answer->fresh()->status);
    }

    #[Group('D1')]
    #[TestDox('Поиски в интернете добавляются к себестоимости по цене за поиск, журнал поисков сохраняется')]
    public function test_web_searches_are_added_to_cost(): void
    {
        config(['ai.gateway.secret' => 'gateway-secret']);

        // Haiku: 30 ₽ / 150 ₽ за 1M; поиск у Anthropic — 1 ₽
        $model = AiModel::where('slug', 'claude-haiku-5-5')->first();
        $answer = User::factory()->create()->chats()->create()->messages()->create([
            'role' => MessageRole::Assistant, 'status' => MessageStatus::Pending, 'ai_model_id' => $model->id,
        ]);
        $searches = [['id' => 'srvtoolu_1', 'status' => 'done', 'query' => 'курс доллара ЦБ']];

        $this->postJson("/internal/gateway/messages/{$answer->id}/complete", [
            'status' => 'done',
            'format' => 'messages',
            'content' => '81,79 ₽',
            'usage' => ['input_tokens' => 14022, 'output_tokens' => 456, 'server_tool_use' => ['web_search_requests' => 1]],
            'web_searches' => 1,
            'searches' => $searches,
        ], ['X-Gateway-Secret' => 'gateway-secret'])
            // (14022 × 30 + 456 × 150) / 1M + 1 поиск × 1 ₽
            ->assertJsonPath('message.cost', ['amount' => '1.48906000', 'currency' => 'RUB'])
            ->assertJsonPath('message.searches', $searches);

        $this->assertSame(1, $answer->fresh()->web_searches);
    }
}
