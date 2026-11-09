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
    public function test_appearance_is_saved_and_rendered_before_first_paint(): void
    {
        $user = User::factory()->create(['settings' => ['other' => 'kept']]);
        $this->actingAs($user);

        // В аккаунте не выбирали — берётся из кук этого браузера, иначе по умолчанию (JIVA, Jura)
        $this->withUnencryptedCookie('theme', 'dark')->withUnencryptedCookie('style', 'classic')->get('/text')
            ->assertSee('data-style="classic" data-theme="dark" data-font="jura"', false);

        $this->patchJson('/settings', ['theme' => 'neon'])->assertUnprocessable();
        $this->patchJson('/settings', ['style' => 'vista'])->assertUnprocessable();
        $this->patchJson('/settings', ['theme' => 'light', 'style' => 'jiva', 'font' => 'exo2'])->assertNoContent();

        $this->assertSame(['other' => 'kept', 'theme' => 'light', 'style' => 'jiva', 'font' => 'exo2'], $user->fresh()->settings);
        // Выбранное в аккаунте важнее кук
        $this->withUnencryptedCookie('theme', 'dark')->withUnencryptedCookie('style', 'classic')->get('/text')
            ->assertSee('data-style="jiva" data-theme="light" data-font="exo2"', false);
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
