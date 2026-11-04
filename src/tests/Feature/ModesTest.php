<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class ModesTest extends TestCase
{
    use RefreshDatabase;

    protected string $seeder = DatabaseSeeder::class;

    protected bool $seed = true;

    #[Group('D1')]
    #[TestDox('Режим «Админ» открыт только администратору')]
    public function test_admin_mode_is_only_for_admins(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get('/admin')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Mode/Placeholder')->where('mode', 'admin'));
    }
}
