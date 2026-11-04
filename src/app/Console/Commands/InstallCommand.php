<?php

namespace App\Console\Commands;

use App\Enums\PlanCode;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('availia:install
    {--email= : Почта суперюзера}
    {--username= : Логин суперюзера}
    {--password= : Пароль суперюзера}')]
#[Description('Первичная установка: миграции, справочники, курсы валют, суперюзер')]
class InstallCommand extends Command
{
    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);

        // Курсы не критичны для установки — их подтянет планировщик
        if ($this->call('exchange-rates:update') !== self::SUCCESS) {
            $this->warn('Курсы валют не загрузились, повтори позже: php artisan exchange-rates:update');
        }

        if (User::where('role', UserRole::Admin)->exists()
            && ! confirm('Администратор уже есть. Создать ещё одного?', default: false)) {
            return self::SUCCESS;
        }

        $this->createSuperuser();

        return self::SUCCESS;
    }

    private function createSuperuser(): void
    {
        $email = $this->option('email') ?? text(
            label: 'Почта суперюзера',
            required: true,
            validate: fn (string $value) => $this->check('email', $value, ['email', 'unique:users,email']),
        );

        $username = $this->option('username') ?? text(
            label: 'Логин',
            required: true,
            validate: fn (string $value) => $this->check('username', $value, ['alpha_dash', 'max:32', 'unique:users,username']),
        );

        $password = $this->option('password') ?? password(
            label: 'Пароль',
            required: true,
            validate: fn (string $value) => $this->check('password', $value, ['min:8']),
        );

        if (! $this->option('password')) {
            password(
                label: 'Пароль ещё раз',
                required: true,
                validate: fn (string $value) => $value === $password ? null : 'Пароли не совпадают',
            );
        }

        // Опции идут мимо интерактивной валидации — проверяем их здесь
        Validator::make(compact('email', 'username', 'password'), [
            'email' => ['required', 'email', 'unique:users,email'],
            'username' => ['required', 'alpha_dash', 'max:32', 'unique:users,username'],
            'password' => ['required', 'min:8'],
        ])->validate();

        (new User)->forceFill([
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'role' => UserRole::Admin,
            'plan_id' => Plan::byCode(PlanCode::Unlimited)->id,
            'email_verified_at' => now(),
        ])->save();

        $this->info("Суперюзер {$username} создан");
    }

    private function check(string $field, string $value, array $rules): ?string
    {
        return Validator::make([$field => $value], [$field => $rules])->errors()->first($field) ?: null;
    }
}
