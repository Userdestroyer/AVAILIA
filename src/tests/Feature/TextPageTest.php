<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class TextPageTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D2')]
    public function test_text_page_lists_active_text_models_by_vendor(): void
    {
        $hidden = AiModel::where('slug', 'claude-opus-5-5')->first();
        $hidden->update(['is_active' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/text')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Text/Index')
                ->has('vendors', 4)
                ->where('vendors.0.product', 'Claude')
                ->where('vendors.0.models', fn ($models) => collect($models)->pluck('id')->doesntContain($hidden->id)
                    && collect($models)->isNotEmpty()));
    }
}
