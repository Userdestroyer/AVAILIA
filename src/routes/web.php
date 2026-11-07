<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ContextController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TextController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/text');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('/text', [TextController::class, 'index'])->name('text');
    Route::get('/text/{chat}', [TextController::class, 'show'])->name('text.chat');
    Route::delete('/text/{chat}', [TextController::class, 'destroy'])->name('text.chat.destroy');
    Route::post('/text/messages', [MessageController::class, 'store'])->name('text.messages');

    // Картриджи контекста «Обо мне» — редактируются в модалке профиля
    Route::get('/context/plates', [ContextController::class, 'index'])->name('context.plates');
    Route::post('/context/plates', [ContextController::class, 'store'])->name('context.plates.store');
    Route::patch('/context/plates/{plate}', [ContextController::class, 'update'])->name('context.plates.update');
    Route::delete('/context/plates/{plate}', [ContextController::class, 'destroy'])->name('context.plates.destroy');
    Route::delete('/text/messages/{message}', [MessageController::class, 'destroy'])->name('text.messages.destroy');
    Route::post('/text/messages/{message}/metrics', [MessageController::class, 'metrics'])->name('text.messages.metrics');
    // Режимы — пока заглушки; переключение без перезагрузки страницы (Inertia)
    Route::inertia('/images', 'Mode/Placeholder', ['mode' => 'images'])->name('images');
    Route::inertia('/video', 'Mode/Placeholder', ['mode' => 'video'])->name('video');
    Route::inertia('/audio', 'Mode/Placeholder', ['mode' => 'audio'])->name('audio');
    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::put('/provider', [AdminController::class, 'activate'])->name('provider');
        Route::put('/service-model', [AdminController::class, 'serviceModel'])->name('service-model');
        Route::post('/models', [AdminController::class, 'storeModel'])->name('models.store');
        Route::patch('/models/{model}', [AdminController::class, 'updateModel'])->name('models.update');
        Route::put('/providers/{provider:slug}/models/{model}', [AdminController::class, 'upsertOffering'])->name('offerings.upsert');
        Route::delete('/providers/{provider:slug}/models/{model}', [AdminController::class, 'destroyOffering'])->name('offerings.destroy');
        Route::post('/providers/polza/sync', [AdminController::class, 'syncPolza'])->name('polza.sync');
    });

    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
