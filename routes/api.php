<?php

use App\Core\Auth\AuthController;
use App\Core\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

// Public (no auth) — rate limited
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1');

// Authenticated (auth:sanctum)
Route::middleware(['auth:sanctum', 'session.governance'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/my-companies', [AuthController::class, 'myCompanies']);

    // Heartbeat (session keepalive — governance middleware handles TTL header)
    Route::post('/heartbeat', fn () => response()->noContent());
});
