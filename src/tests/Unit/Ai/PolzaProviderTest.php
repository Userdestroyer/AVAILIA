<?php

namespace Tests\Unit\Ai;

use App\Enums\MessageRole;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\ProviderModel;
use App\Models\Vendor;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Ai\PolzaProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class PolzaProviderTest extends TestCase
{
    #[Group('U1')]
    #[TestDox('Polza: id модели и источник из предложения, поиск — polza:web_search с лимитом и доменами в обычном chat, лимит выше 10 не срезается')]
    public function test_request_uses_upstream_and_polza_search(): void
    {
        $model = (new AiModel(['slug' => 'claude-haiku-5-5']))->setRelation('vendor', new Vendor(['slug' => 'anthropic']));
        $offer = (new ProviderModel(['external_id' => 'anthropic/claude-haiku-5.5', 'upstream' => 'amazon-bedrock']))
            ->setRelation('aiModel', $model)
            ->setRelation('capabilities', collect());
        $request = fn (int $searches) => (new PolzaProvider('https://polza.ai/api/v1', 'key'))->request(
            $offer,
            [new Message(['role' => MessageRole::User, 'content' => 'Курс?'])],
            new ChatOptions(maxTokens: 2048, webSearch: true, maxWebSearches: $searches, allowedDomains: ['cbr.ru']),
        );
        $this->assertArrayNotHasKey('max_tool_calls', $request(2)->body);
        // Выше 10 поисков упирается в общий лимит серверных вызовов — поднимаем его
        $this->assertSame(15, $request(15)->body['max_tool_calls']);

        $request = $request(2);

        $this->assertSame(['chat', '/chat/completions'], [$request->format, $request->path]);
        $this->assertSame('anthropic/claude-haiku-5.5', $request->body['model']);
        // Закреплённый источник — первым, но с фолбэком внутри Polza
        $this->assertSame(['order' => ['amazon-bedrock'], 'allow_fallbacks' => true], $request->body['provider']);
        $this->assertSame([['type' => 'polza:web_search', 'parameters' => ['max_uses' => 2, 'allowed_domains' => ['cbr.ru']]]], $request->body['tools']);
        $this->assertSame(['include_usage' => true, 'include_server_tool_events' => true], $request->body['stream_options']);
    }

    #[Group('U1')]
    #[TestDox('Polza сам сообщает стоимость в рублях — берём её')]
    public function test_reported_cost_comes_from_cost_rub(): void
    {
        $provider = new PolzaProvider('https://polza.ai/api/v1', 'key');

        $this->assertSame(['amount' => '0.51955000', 'currency' => 'RUB'], $provider->reportedCost(['cost_rub' => 0.51955]));
        $this->assertNull($provider->reportedCost(['prompt_tokens' => 10]));
    }
}
