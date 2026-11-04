<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D2')]
    public function test_theme_is_saved_and_rendered_before_first_paint(): void
    {
        $user = User::factory()->create(['settings' => ['other' => 'kept']]);
        $this->actingAs($user);

        $this->patchJson('/settings', ['theme' => 'neon'])->assertUnprocessable();
        $this->patchJson('/settings', ['theme' => 'dark'])->assertNoContent();

        $this->assertSame(['other' => 'kept', 'theme' => 'dark'], $user->fresh()->settings);
        $this->get('/text')->assertSee('data-theme="dark"', false);
    }
}
