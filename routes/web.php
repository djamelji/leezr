<?php

use App\Modules\Infrastructure\System\Http\HealthController;
use Illuminate\Support\Facades\Route;

// Health endpoint (ADR-046 F4) — public, no auth, no-cache headers
Route::get('/health', HealthController::class)
    ->middleware([\App\Http\Middleware\NoCacheHeaders::class, \App\Http\Middleware\AddBuildVersion::class]);

Route::get('{any?}', function() {
    return view('application');
})->where('any', '^(?!api|sanctum|health).*$')
  ->middleware(['maintenance.check', \App\Http\Middleware\NoCacheHeaders::class]);