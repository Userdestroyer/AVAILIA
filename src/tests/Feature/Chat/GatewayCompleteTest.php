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
}
