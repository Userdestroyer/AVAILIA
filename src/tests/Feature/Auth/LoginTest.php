<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Гость не попадает в приложение и уходит на страницу входа')]
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/text');
        $this->get('/text')->assertRedirect('/login');
    }

    #[Group('D3')]
    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    #[Group('D1')]
    #[TestDox('Пользователь входит по почте и паролю и выходит с инвалидацией сессии')]
    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/text');
        $this->assertAuthenticatedAs($user);

        $this->get('/text')->assertOk();
        $this->get('/login')->assertRedirect('/text');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[Group('D1')]
    #[TestDox('Неверный пароль не авторизует, после 5 попыток вход блокируется даже с верным паролем')]
    public function test_wrong_password_is_rejected_and_throttled(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'Неверная почта или пароль.']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
