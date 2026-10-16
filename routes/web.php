<?php

use App\Modules\Infrastructure\System\Http\HealthController;
use App\Modules\Infrastructure\System\Http\StorageFileController;
use App\Modules\Infrastructure\System\Http\SchedulerHealthEndpointController;
use Illuminate\Support\Facades\Route;

// Health endpoint (ADR-046 F4) — public, no auth, no-cache headers
Route::get('/health', HealthController::class)
    ->middleware([\App\Http\Middleware\NoCacheHeaders::class, \App\Http\Middleware\AddBuildVersion::class]);

// External scheduler-liveness probe (token-gated; disabled when no token configured).
// For an independent off-host uptime monitor — a dead scheduler can't self-alert.
Route::get('/health/scheduler', SchedulerHealthEndpointController::class)
    ->middleware([\App\Http\Middleware\NoCacheHeaders::class]);

// Public storage files — served via PHP (ISPConfig blocks /storage/ at Apache level)
// Route uses /media/ prefix to bypass ISPConfig restriction (ADR-401)
Route::get('/media/{path}', StorageFileController::class)
    ->where('path', '.*');

Route::get('{any?}', function() {
    return view('application');
})->where('any', '^(?!api|sanctum|health|media).*$')
  ->middleware(['maintenance.check', \App\Http\Middleware\NoCacheHeaders::class]);