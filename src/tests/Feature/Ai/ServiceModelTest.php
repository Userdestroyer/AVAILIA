<?php

namespace Tests\Feature\Ai;

use App\Jobs\GenerateChatTitle;
use App\Models\ServiceCall;
use App\Models\User;
use App\Services\Ai\ServiceModel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ServiceModelTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Название чата придумывает сервисная модель; фоновый вызов учитывается со стоимостью, переименованный чат не трогается')]
    public function test_chat_title_comes_from_service_model_and_is_accounted(): void
    {
        config(['ai.providers.proxyapi' => ['url' => 'https://proxy.test/v1', 'key' => 'secret']]);
        Http::fake(['proxy.test/*' => Http::response([
            'choices' => [['message' => ['content' => '«Курс доллара на сегодня».']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10],
        ])]);

        $content = 'Какой сегодня курс доллара по ЦБ и почему он так скачет?';
        $chat = User::factory()->create()->chats()->create(['title' => GenerateChatTitle::fallback($content)]);
        $renamed = $chat->user->chats()->create(['title' => 'Своё название']);

        (new GenerateChatTitle($chat->id, $content))->handle(app(ServiceModel::class));
        (new GenerateChatTitle($renamed->id, $content))->handle(app(ServiceModel::class));

        $this->assertSame('Курс доллара на сегодня', $chat->fresh()->title);
        $this->assertSame('Своё название', $renamed->fresh()->title);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['model'] === 'anthropic/claude-haiku-5-5' && $request['messages'][0]['role'] === 'system');

        // Haiku у proxyapi: (100 × 30 + 10 × 150) / 1M
        $call = ServiceCall::sole();
        $this->assertSame(['chat_title', 'done', $chat->user_id, '0.00450000'], [$call->purpose, $call->status, $call->user_id, $call->cost]);
    }
}
