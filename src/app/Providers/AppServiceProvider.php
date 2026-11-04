<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\ProxyApiProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiProvider::class, function () {
            $name = config('ai.provider');
            $config = config("ai.providers.{$name}");

            return match ($name) {
                'proxyapi' => new ProxyApiProvider($config['url'], $config['key']),
                default => throw new InvalidArgumentException("Неизвестный AI-провайдер: {$name}"),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());
    }
}
