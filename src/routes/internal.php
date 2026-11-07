<?php

use App\Http\Controllers\Internal\GatewayController;
use Illuminate\Support\Facades\Route;

// withTrashed: если юзер успел удалить вопрос, ответ всё равно сохраним и учтём его стоимость
Route::post('/gateway/messages/{message}/complete', [GatewayController::class, 'complete'])->withTrashed();
