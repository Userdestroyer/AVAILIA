<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Внутренние вызовы от Go-гейтвея: без web-middleware (сессии, CSRF),
        // но с подстановкой моделей из URL — её даёт web-группа, а здесь её нет
        then: function () {
            Route::prefix('internal')
                ->middleware(SubstituteBindings::class)
                ->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->redirectUsersTo('/text');

        // Тему пишет сам браузер (мгновенно, до ответа сервера) — шифровать эту куку нельзя
        $middleware->encryptCookies(except: ['theme', 'style', 'font']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
