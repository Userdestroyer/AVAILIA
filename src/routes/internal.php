<?php

use App\Http\Controllers\Internal\GatewayController;
use Illuminate\Support\Facades\Route;

Route::post('/gateway/messages/{message}/complete', [GatewayController::class, 'complete']);
