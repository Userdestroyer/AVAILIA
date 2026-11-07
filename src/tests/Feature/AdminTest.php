<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiModel;
use App\Models\Provider;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\ServiceModel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Админка, смена провайдера и сервисной модели — только администратору; выбор применяется')]
    public function test_only_admin_manages_provider(): void
    {
        $luna = AiModel::where('slug', 'gpt-6-luna')->first();
        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertForbidden();
        $this->putJson('/admin/provider', ['slug' => 'polza'])->assertForbidden();
        $this->putJson('/admin/service-model', ['ai_model_id' => $luna->id])->assertForbidden();
        $this->putJson('/admin/providers/polza/models/'.AiModel::first()->id, ['external_id' => 'x'])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Index')
            ->has('providers', 2)
            ->where('activeProvider', 'proxyapi'));

        $this->putJson('/admin/provider', ['slug' => 'polza'])->assertNoContent();
        $this->assertSame('polza', app(ProviderRegistry::class)->activeSlug());

        $this->assertSame('claude-haiku-5-5', app(ServiceModel::class)->model()->slug);
        $this->putJson('/admin/service-model', ['ai_model_id' => $luna->id])->assertNoContent();
        $this->assertSame('gpt-6-luna', app(ServiceModel::class)->model()->slug);
    }

    #[Group('D1')]
    #[TestDox('Новую модель без расценок можно подключить к провайдеру — она доступна в чате и ничего не ломает')]
    public function test_new_model_without_prices_is_usable(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $anthropic = Vendor::where('slug', 'anthropic')->first();

        $model = $this->postJson('/admin/models', ['vendor_id' => $anthropic->id, 'name' => 'Claude Next', 'slug' => 'claude-next'])
            ->assertCreated()
            ->json('model');
        $this->putJson("/admin/providers/proxyapi/models/{$model['id']}", ['external_id' => 'anthropic/claude-next', 'price_input' => null, 'capabilities' => ['temperature']])
            ->assertOk()
            ->assertJsonPath('offering.price_input', null)
            ->assertJsonPath('offering.capabilities', ['temperature']);

        $this->get('/text')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('vendors.0.models', fn ($models) => collect($models)->contains(fn ($m) => $m['id'] === $model['id'] && $m['price_input'] === null
                // Возможность без своих ограничений — с пределами по умолчанию
                && $m['capabilities'] === ['temperature' => ['min' => 0, 'max' => 2]])));
    }

    #[Group('D1')]
    #[TestDox('Каталог Polza обновляет у подключённых моделей цены, лимиты и возможности')]
    public function test_polza_catalog_syncs_prices_limits_and_capabilities(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        Http::fake(['*/models*' => Http::response(['data' => [[
            'id' => 'anthropic/claude-haiku-5.5',
            'architecture' => ['input_modalities' => ['text', 'image']],
            'top_provider' => [
                'context_length' => 200000,
                'max_completion_tokens' => 64000,
                'supported_parameters' => ['max_tokens', 'temperature', 'tools'],
                'pricing' => ['prompt_per_million' => '10.5', 'completion_per_million' => '50', 'currency' => 'RUB'],
            ],
        ]]])]);

        $this->postJson('/admin/providers/polza/sync')->assertOk()->assertJsonPath('updated', 1);

        $offer = AiModel::where('slug', 'claude-haiku-5-5')->first()->offerings()->where('provider_id', Provider::where('slug', 'polza')->value('id'))->first();
        $this->assertSame(['10.500000', '50.000000', null], [$offer->price_input, $offer->price_output, $offer->price_cache_read]);
        $this->assertSame([200000, 64000], [$offer->context_length, $offer->max_output_tokens]);
        // Рассуждения и файлы из сидера пропали — в каталоге их больше нет; поиск у Polza есть всегда
        $this->assertEqualsCanonicalizing(['temperature', 'images', 'web_search'], $offer->capabilities->map(fn ($row) => $row->capability->value)->all());
    }
}
