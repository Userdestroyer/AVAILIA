<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\AiModel;
use App\Models\Provider;
use App\Models\ProviderModel;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class GatewayCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Без секрета гейтвея — 403; с секретом ответ сохраняется с себестоимостью по usage и скоростью, повтор ничего не меняет')]
    public function test_gateway_completes_answer_with_cost(): void
    {
        config(['ai.gateway.secret' => 'gateway-secret']);

        // proxyapi: 1580 ₽ / 7900 ₽ за 1M; кэш без своей цены — по цене ввода
        $answer = $this->pendingAnswer('proxyapi', 'claude-fable-5-1');
        $url = "/internal/gateway/messages/{$answer->id}/complete";
        $result = [
            'status' => 'done',
            'content' => 'Ответ',
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 350, 'prompt_tokens_details' => ['cached_tokens' => 200]],
            'ttft_ms' => 640,
            'duration_ms' => 5200,
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
        $this->assertSame([640, 5200], [$answer->ttft_ms, $answer->duration_ms]);

        $this->postJson($url, ['status' => 'failed', 'error' => 'late'], ['X-Gateway-Secret' => 'gateway-secret'])->assertOk();
        $this->assertSame(MessageStatus::Done, $answer->fresh()->status);
    }

    public static function costs(): array
    {
        $searches = [['id' => 'srvtoolu_1', 'status' => 'done', 'query' => 'курс доллара ЦБ']];

        return [
            // Haiku у proxyapi: 30 ₽ / 150 ₽ за 1M, поиск 1 ₽ → (14022 × 30 + 456 × 150) / 1M + 1
            'proxyapi — по расценкам, с поиском' => ['proxyapi', true, [
                'format' => 'messages',
                'usage' => ['input_tokens' => 14022, 'output_tokens' => 456],
                'web_searches' => 1,
                'searches' => $searches,
            ], ['amount' => '1.48906000', 'currency' => 'RUB'], $searches, ['1.489060', false]],
            // Polza называет стоимость сама — она точнее расценок (источник, кэш, поиск уже учтены)
            'polza — стоимость от провайдера' => ['polza', true, [
                'usage' => ['prompt_tokens' => 27, 'completion_tokens' => 91, 'cost_rub' => 0.51955],
                'web_searches' => 1,
            ], ['amount' => '0.51955000', 'currency' => 'RUB'], [], ['0.519550', false]],
            // Расценок нет, провайдер стоимость не сообщил — неизвестна, но ответ сохраняется,
            // а в лимит идёт оценка по запасной цене (1000 / 5000 ₽ за 1M): (27 × 1000 + 91 × 5000) / 1M
            'нет расценок — стоимость неизвестна' => ['proxyapi', false, [
                'usage' => ['prompt_tokens' => 27, 'completion_tokens' => 91],
            ], null, [], ['0.482000', true]],
        ];
    }

    #[Group('D1')]
    #[DataProvider('costs')]
    #[TestDox('Себестоимость ответа: от провайдера, если сообщил, иначе по расценкам с поиском; без расценок — неизвестна, а в лимит идёт оценка')]
    public function test_answer_cost(string $provider, bool $withPrices, array $result, ?array $cost, array $searches, array $charged): void
    {
        config(['ai.gateway.secret' => 'gateway-secret']);
        $answer = $this->pendingAnswer($provider, 'claude-haiku-5-5');
        if (! $withPrices) {
            ProviderModel::where('provider_id', $answer->provider_id)->update(['price_input' => null, 'price_output' => null]);
        }

        $this->postJson("/internal/gateway/messages/{$answer->id}/complete", ['status' => 'done', 'content' => 'Ответ', ...$result],
            ['X-Gateway-Secret' => 'gateway-secret'])
            ->assertOk()
            ->assertJsonPath('message.status', 'done')
            ->assertJsonPath('message.cost', $cost)
            ->assertJsonPath('message.searches', $searches);

        // Трата — в текстовый лимит владельца чата: [₽, оценка ли]
        $entry = $answer->chat->user->usageEntries()->sole();
        $this->assertSame($charged, [$entry->amount, $entry->estimated]);
    }

    private function pendingAnswer(string $provider, string $model)
    {
        return User::factory()->create()->chats()->create()->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Pending,
            'ai_model_id' => AiModel::where('slug', $model)->value('id'),
            'provider_id' => Provider::where('slug', $provider)->value('id'),
        ]);
    }
}
