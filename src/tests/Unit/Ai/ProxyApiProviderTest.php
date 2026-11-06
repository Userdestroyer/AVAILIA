<?php

namespace Tests\Unit\Ai;

use App\Enums\MessageRole;
use App\Models\AiModel;
use App\Models\Message;
use App\Models\Vendor;
use App\Services\Ai\Data\ChatOptions;
use App\Services\Ai\ProxyApiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ProxyApiProviderTest extends TestCase
{
    public static function modelIds(): array
    {
        return [
            'префикс совпадает со slug' => ['anthropic', 'claude-opus-5-5', 'anthropic/claude-opus-5-5'],
            'у xAI префикс x-ai' => ['xai', 'grok-4.7', 'x-ai/grok-4.7'],
        ];
    }

    #[Group('U2')]
    #[DataProvider('modelIds')]
    public function test_model_id_has_vendor_prefix(string $vendor, string $slug, string $expected): void
    {
        $this->assertSame($expected, $this->provider()->modelId($this->model($vendor, $slug)));
    }

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
            $this->model($vendor, 'model'),
            [new Message(['role' => MessageRole::User, 'content' => 'Курс?'])],
            new ChatOptions(maxTokens: 2048, webSearch: $search, maxWebSearches: 3, allowedDomains: ['cbr.ru']),
        );

        $this->assertSame([$format, $path], [$request->format, $request->path]);
        $this->assertSame($tools, $request->body['tools'] ?? null);
        $this->assertTrue($request->body['stream']);
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

    private function model(string $vendor, string $slug): AiModel
    {
        return (new AiModel(['slug' => $slug]))->setRelation('vendor', new Vendor(['slug' => $vendor]));
    }

    private function provider(): ProxyApiProvider
    {
        return new ProxyApiProvider('https://api.proxyapi.ru/v1', 'key');
    }
}
