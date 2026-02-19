<?php

use App\Core\Auth\AuthController;
use App\Core\Auth\PasswordResetController;
use App\Modules\Platform\Audience\Http\AudienceController;
use Illuminate\Support\Facades\Route;

// Public (no auth) — rate limited
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->middleware('throttle:5,1');

// Audience — public (throttled, no auth)
Route::prefix('audience')->middleware('throttle:10,1')->group(function () {
    Route::post('/subscribe', [AudienceController::class, 'subscribe']);
    Route::post('/confirm', [AudienceController::class, 'confirm']);
    Route::post('/unsubscribe', [AudienceController::class, 'unsubscribe']);
    Route::get('/maintenance-page', [AudienceController::class, 'maintenancePage']);
});

// Authenticated (auth:sanctum)
Route::middleware(['auth:sanctum', 'session.governance'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/my-companies', [AuthController::class, 'myCompanies']);

    // Heartbeat (session keepalive — governance middleware handles TTL header)
    Route::post('/heartbeat', fn () => response()->noContent());
});
