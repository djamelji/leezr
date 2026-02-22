<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health endpoint (ADR-046 F4) — public, no auth, no-cache headers
Route::get('/health', HealthController::class)
    ->middleware(\App\Http\Middleware\NoCacheHeaders::class);

Route::get('{any?}', function() {
    return view('application');
})->where('any', '^(?!api|sanctum|health).*$')
  ->middleware('maintenance.check');