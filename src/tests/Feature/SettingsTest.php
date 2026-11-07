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

        // Тему в аккаунте не выбирали — берётся из куки этого браузера, а не «светлая по умолчанию»
        $this->withUnencryptedCookie('theme', 'dark')->get('/text')->assertSee('data-theme="dark"', false);

        $this->patchJson('/settings', ['theme' => 'neon'])->assertUnprocessable();
        $this->patchJson('/settings', ['theme' => 'light'])->assertNoContent();

        $this->assertSame(['other' => 'kept', 'theme' => 'light'], $user->fresh()->settings);
        // Выбранная в аккаунте важнее куки
        $this->withUnencryptedCookie('theme', 'dark')->get('/text')->assertSee('data-theme="light"', false);
    }

    #[Group('D2')]
    public function test_chat_settings_are_saved_without_unknown_keys(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->patchJson('/settings', ['chat' => ['mode' => 'multi', 'max_web_searches' => 21]])->assertUnprocessable();
        $this->patchJson('/settings', ['chat' => [
            'mode' => 'multi', 'model_id' => 3, 'multi_ids' => [3, 5], 'token_step' => 5, 'effort' => 'high',
            'deep_thinking' => true, 'web_search' => true, 'max_web_searches' => 2, 'domains' => 'cbr.ru', 'evil' => 'x',
        ]])->assertNoContent();

        $chat = $user->fresh()->settings['chat'];
        $this->assertSame([3, 5], $chat['multi_ids']);
        $this->assertArrayNotHasKey('evil', $chat);
    }
}
