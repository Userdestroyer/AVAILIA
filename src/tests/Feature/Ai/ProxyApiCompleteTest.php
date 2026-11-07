<?php

namespace Tests\Feature\Ai;

use App\Enums\Capability;
use App\Enums\MessageRole;
use App\Enums\ReasoningEffort;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\ProviderModel;
use App\Models\ProviderModelCapability;
use App\Models\Vendor;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\Data\ChatOptions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ProxyApiCompleteTest extends TestCase
{
    #[Group('A1')]
    #[TestDox('Запрос уходит в OpenAI-совместимый chat/completions proxyapi с ключом и параметрами, ответ и usage разбираются')]
    public function test_complete_sends_openai_compatible_request(): void
    {
        config(['ai.providers.proxyapi' => ['url' => 'https://proxy.test/v1', 'key' => 'secret']]);

        Http::fake([
            'proxy.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Привет!']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5],
            ]),
        ]);

        $model = (new AiModel(['slug' => 'claude-sonnet-5-5']))->setRelation('vendor', new Vendor(['slug' => 'anthropic']));
        $offer = (new ProviderModel(['external_id' => 'anthropic/claude-sonnet-5-5']))
            ->setRelation('aiModel', $model)
            ->setRelation('capabilities', collect([new ProviderModelCapability(['capability' => Capability::Reasoning])]));
        $messages = [new Message(['role' => MessageRole::User, 'content' => 'Привет'])];

        $completion = app(ProviderRegistry::class)->provider('proxyapi')->complete(
            $offer, $messages, new ChatOptions(maxTokens: 1024, reasoningEffort: ReasoningEffort::High),
        );

        $this->assertSame('Привет!', $completion->content);
        $this->assertSame([12, 5], [$completion->usage->input, $completion->usage->output]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://proxy.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request['model'] === 'anthropic/claude-sonnet-5-5'
            && $request['messages'] === [['role' => 'user', 'content' => 'Привет']]
            && $request['max_tokens'] === 1024
            && $request['reasoning_effort'] === 'high'
            && ! isset($request['stream']));
    }
}
