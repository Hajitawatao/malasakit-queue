<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CounterController;
use App\Http\Controllers\Api\DisplayController;
use App\Http\Controllers\Api\KioskController;
use App\Http\Middleware\EnsureDeviceKey;
use Illuminate\Support\Facades\Route;

// ---- Kiosk (touchscreen) ----
Route::middleware([EnsureDeviceKey::class, 'throttle:60,1'])->prefix('kiosk')->group(function () {
    Route::get('services', [KioskController::class, 'services']);
    Route::post('tickets', [KioskController::class, 'issue']);
});

// ---- TV display (read-only, public sa LAN) ----
Route::get('display', [DisplayController::class, 'snapshot']);
Route::get('display/test-voice', [DisplayController::class, 'testVoice'])->middleware('throttle:20,1');

// ---- Counter staff ----
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::prefix('counter')->group(function () {
        Route::get('state', [CounterController::class, 'state']);
        Route::post('call-next', [CounterController::class, 'callNext']);
        Route::post('tickets/{ticket}/recall', [CounterController::class, 'recall']);
        Route::post('tickets/{ticket}/serve', [CounterController::class, 'serve']);
        Route::post('tickets/{ticket}/done', [CounterController::class, 'done']);
        Route::post('tickets/{ticket}/no-show', [CounterController::class, 'noShow']);
    });
});
