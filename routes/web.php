<?php

use App\Http\Controllers\StorageFileController;
use App\Modules\Infrastructure\System\Http\HealthController;
use Illuminate\Support\Facades\Route;

// Health endpoint (ADR-046 F4) — public, no auth, no-cache headers
Route::get('/health', HealthController::class)
    ->middleware([\App\Http\Middleware\NoCacheHeaders::class, \App\Http\Middleware\AddBuildVersion::class]);

// Public storage files — PHP fallback for ISPConfig (SymLinksIfOwnerMatch blocks symlinks)
// Must be BEFORE the SPA catch-all (ADR-401)
Route::get('/storage/{path}', StorageFileController::class)
    ->where('path', '.*');

Route::get('{any?}', function() {
    return view('application');
})->where('any', '^(?!api|sanctum|health|storage).*$')
  ->middleware(['maintenance.check', \App\Http\Middleware\NoCacheHeaders::class]);