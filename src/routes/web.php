<?php

use App\Http\Controllers\Auth\LoginController;
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
    // Режимы — пока заглушки; переключение без перезагрузки страницы (Inertia)
    Route::inertia('/images', 'Mode/Placeholder', ['mode' => 'images'])->name('images');
    Route::inertia('/video', 'Mode/Placeholder', ['mode' => 'video'])->name('video');
    Route::inertia('/audio', 'Mode/Placeholder', ['mode' => 'audio'])->name('audio');
    Route::inertia('/admin', 'Mode/Placeholder', ['mode' => 'admin'])->name('admin')->can('admin');

    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
