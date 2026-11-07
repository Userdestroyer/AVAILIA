<?php

namespace Tests\Unit\Ai;

use App\Enums\Capability;
use App\Enums\MessageRole;
use App\Enums\ReasoningEffort;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\ProviderModel;
use App\Models\ProviderModelCapability;
use App\Models\Vendor;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Ai\ProxyApiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ProxyApiProviderTest extends TestCase
{
    public static function searchRequests(): array
    {
        return [
            // Claude соблюдает лимит и домены только в родном Messages
            'Claude — Messages, лимит и домены' => ['anthropic', true, 'messages', '/messages',
                [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3, 'allowed_domains' => ['cbr.ru']]]],
            // У GPT нет лимита поисков, домены — через filters
            'GPT — Responses, только домены' => ['openai', true, 'responses', '/responses',
                [['type' => 'web_search', 'filters' => ['allowed_domains' => ['cbr.ru']]]]],
            // Gemini принимает лимит, доменов не поддерживает
            'Gemini — Responses, только лимит' => ['google', true, 'responses', '/responses',
                [['type' => 'web_search', 'max_uses' => 3]]],
            'Grok — поиска нет, обычный chat' => ['xai', true, 'chat', '/chat/completions', null],
            'без поиска — всегда chat' => ['anthropic', false, 'chat', '/chat/completions', null],
        ];
    }

    #[Group('U1')]
    #[DataProvider('searchRequests')]
    #[TestDox('Поиск идёт в формате, где вендор его поддерживает, и только с ограничениями, которые он соблюдает')]
    public function test_request_uses_format_with_web_search(string $vendor, bool $search, string $format, string $path, ?array $tools): void
    {
        $request = $this->provider()->request(
            $this->offer($vendor, 'vendor/model-at-proxyapi'),
            [new Message(['role' => MessageRole::User, 'content' => 'Курс?'])],
            new ChatOptions(maxTokens: 2048, webSearch: $search, maxWebSearches: 3, allowedDomains: ['cbr.ru']),
        );

        $this->assertSame([$format, $path], [$request->format, $request->path]);
        $this->assertSame('vendor/model-at-proxyapi', $request->body['model']);
        $this->assertSame($tools, $request->body['tools'] ?? null);
        $this->assertTrue($request->body['stream']);
    }

    public static function capabilityRequests(): array
    {
        $all = ['temperature' => ['max' => 1.5], 'reasoning' => null];

        return [
            // Температура зажимается в пределы модели, лимит ответа — в её максимум
            'chat, умеет всё' => ['xai', false, $all, ['temperature' => 1.5, 'reasoning_effort' => 'high', 'max_tokens' => 1024]],
            'chat, ничего не умеет' => ['xai', false, [], ['temperature' => null, 'reasoning_effort' => null, 'max_tokens' => 1024]],
            'Responses (Gemini с поиском)' => ['google', true, $all, ['temperature' => 1.5, 'reasoning' => ['effort' => 'high'], 'max_output_tokens' => 1024]],
            'Messages (Claude с поиском)' => ['anthropic', true, ['temperature' => ['max' => 1]], ['temperature' => 1.0, 'max_tokens' => 1024]],
        ];
    }

    #[Group('U1')]
    #[DataProvider('capabilityRequests')]
    #[TestDox('Параметр уходит только модели, которая его принимает, и в её пределах — в любом формате запроса')]
    public function test_parameters_follow_model_capabilities(string $vendor, bool $search, array $capabilities, array $expected): void
    {
        $offer = $this->offer($vendor, 'vendor/model', $capabilities);
        $offer->max_output_tokens = 1024;

        $body = $this->provider()->request(
            $offer,
            [new Message(['role' => MessageRole::User, 'content' => 'Привет'])],
            new ChatOptions(maxTokens: 4096, reasoningEffort: ReasoningEffort::High, temperature: 1.8, webSearch: $search),
        )->body;

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $body[$key] ?? null, $key);
        }
    }

    public static function usages(): array
    {
        return [
            // В OpenAI-форматах кэш входит во ввод — вычитаем
            'chat' => ['chat', ['prompt_tokens' => 1000, 'completion_tokens' => 300, 'prompt_tokens_details' => ['cached_tokens' => 600, 'cache_write_tokens' => 100]], [300, 300, 600, 100]],
            'responses' => ['responses', ['input_tokens' => 1000, 'output_tokens' => 300, 'input_tokens_details' => ['cached_tokens' => 600, 'cache_write_tokens' => 100]], [300, 300, 600, 100]],
            // В Anthropic кэш отдельно от ввода
            'messages' => ['messages', ['input_tokens' => 300, 'output_tokens' => 300, 'cache_read_input_tokens' => 600, 'cache_creation_input_tokens' => 100], [300, 300, 600, 100]],
        ];
    }

    #[Group('U1')]
    #[DataProvider('usages')]
    #[TestDox('usage любого формата раскладывается по ценам: каждый токен — ровно по одной цене')]
    public function test_usage_splits_cached_tokens_out_of_input(string $format, array $raw, array $expected): void
    {
        $usage = $this->provider()->parseUsage($raw, $format);

        $this->assertSame($expected, [$usage->input, $usage->output, $usage->cacheRead, $usage->cacheWrite]);
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $capabilities  capability => settings
     */
    private function offer(string $vendor, string $externalId, array $capabilities = []): ProviderModel
    {
        $model = (new AiModel(['slug' => 'model']))->setRelation('vendor', new Vendor(['slug' => $vendor]));
        $rows = collect($capabilities)->map(fn ($settings, $capability) => new ProviderModelCapability([
            'capability' => Capability::from($capability), 'settings' => $settings,
        ]))->values();

        return (new ProviderModel(['external_id' => $externalId]))->setRelation('aiModel', $model)->setRelation('capabilities', $rows);
    }

    private function provider(): ProxyApiProvider
    {
        return new ProxyApiProvider('https://api.proxyapi.ru/v1', 'key');
    }
}
