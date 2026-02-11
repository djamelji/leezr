<?php

use App\Platform\Http\Controllers\PlatformModuleController;
use Illuminate\Support\Facades\Route;

// Platform-scoped routes
// Middleware: auth:sanctum + platform.admin (applied in bootstrap/app.php)
// All routes here require is_platform_admin on the user

// Module catalog management
Route::get('/modules', [PlatformModuleController::class, 'index']);
Route::put('/modules/{key}/toggle', [PlatformModuleController::class, 'toggle']);
