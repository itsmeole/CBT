<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('time', fn () => response()->json(['time' => (int) (microtime(true) * 1000)]));

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});
