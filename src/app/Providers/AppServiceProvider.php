<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Ai\ProviderRegistry;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Один на запрос: кэширует активного провайдера и экземпляры классов провайдеров
        $this->app->scoped(ProviderRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // Через конфиг, а не env() в bootstrap/app.php — на проде конфиг кэшируется
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies);
        }
    }
}
