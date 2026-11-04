<?php

namespace Tests\Unit\Ai;

use App\Models\AiModel;
use App\Models\Vendor;
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
        $model = (new AiModel(['slug' => $slug]))->setRelation('vendor', new Vendor(['slug' => $vendor]));

        $this->assertSame($expected, $this->provider()->modelId($model));
    }

    #[Group('U1')]
    #[TestDox('Кэшированные токены вычитаются из ввода — каждый токен считается по одной цене')]
    public function test_usage_splits_cached_tokens_out_of_input(): void
    {
        $usage = $this->provider()->parseUsage([
            'prompt_tokens' => 1000,
            'completion_tokens' => 300,
            'prompt_tokens_details' => ['cached_tokens' => 600, 'cache_write_tokens' => 100],
        ]);

        $this->assertSame([300, 300, 600, 100], [$usage->input, $usage->output, $usage->cacheRead, $usage->cacheWrite]);
    }

    private function provider(): ProxyApiProvider
    {
        return new ProxyApiProvider('https://api.proxyapi.ru/v1', 'key');
    }
}
