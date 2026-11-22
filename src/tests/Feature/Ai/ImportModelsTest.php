<?php

namespace Tests\Feature\Ai;

use App\Models\AiModel;
use App\Models\Provider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ImportModelsTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Импорт из каталога Polza добавляет скрытыми только самые свежие модели нужных вендоров — с ценами, лимитами и возможностями; подключённые не дублирует')]
    public function test_imports_freshest_models_hidden(): void
    {
        $item = fn (string $id, string $name, int $created, array $inputs = ['text']) => [
            'id' => $id,
            'name' => $name,
            'created' => $created,
            'architecture' => ['input_modalities' => $inputs],
            'top_provider' => [
                'context_length' => 400000,
                'max_completion_tokens' => 64000,
                'supported_parameters' => ['temperature'],
                'pricing' => ['prompt_per_million' => '12', 'completion_per_million' => '60', 'currency' => 'RUB'],
            ],
        ];
        Http::fake(['*/models*' => Http::response(['data' => [
            $item('anthropic/claude-opus-6', 'Anthropic: Claude Opus 6', 300, ['text', 'image', 'file']),
            // Уже подключена (сидер) — не дублируется, но место в «свежих» занимает
            $item('anthropic/claude-haiku-5.5', 'Anthropic: Claude Haiku 5.5', 200),
            // Старше двух самых свежих — не берём
            $item('anthropic/claude-sonnet-4.5', 'Anthropic: Claude Sonnet 4.5', 100),
            $item('x-ai/grok-5', 'SpaceXAI: Grok 5', 250),
            // Вендора нет в списке импорта
            $item('mistralai/mistral-large', 'Mistral: Large', 400),
        ]])]);

        $this->artisan('models:import', ['--limit' => 2])->assertSuccessful();

        $polza = Provider::where('slug', 'polza')->value('id');
        $imported = AiModel::whereIn('slug', ['claude-opus-6', 'grok-5'])->with('offerings.capabilities')->orderBy('slug')->get();
        $this->assertSame(['Claude Opus 6', 'Grok 5'], $imported->pluck('name')->all());
        $this->assertSame([false, false], $imported->pluck('is_active')->all());

        $offer = $imported[0]->offerings->sole();
        $this->assertSame([$polza, 'anthropic/claude-opus-6', '12.000000', '60.000000', 400000], [
            $offer->provider_id, $offer->external_id, $offer->price_input, $offer->price_output, $offer->context_length,
        ]);
        $this->assertEqualsCanonicalizing(['temperature', 'images', 'files', 'web_search'], $offer->capabilities->map(fn ($row) => $row->capability->value)->all());

        $this->assertFalse(AiModel::where('slug', 'claude-sonnet-4.5')->exists());
        $this->assertFalse(AiModel::where('slug', 'mistral-large')->exists());
        $this->assertSame(1, AiModel::where('name', 'Claude Haiku 5.5')->count());

        // Повторный запуск ничего не добавляет
        $count = AiModel::count();
        $this->artisan('models:import', ['--limit' => 2])->assertSuccessful();
        $this->assertSame($count, AiModel::count());
    }
}
