<?php

use App\Platform\Auth\PlatformAuthController;
use App\Platform\Http\Controllers\PlatformCompanyController;
use App\Platform\Http\Controllers\PlatformCompanyUserController;
use App\Platform\Http\Controllers\PlatformModuleController;
use App\Platform\Http\Controllers\PlatformPermissionController;
use App\Platform\Http\Controllers\PlatformRoleController;
use App\Platform\Http\Controllers\PlatformUserController;
use Illuminate\Support\Facades\Route;

// Platform auth — public (throttled)
Route::post('/login', [PlatformAuthController::class, 'login'])
    ->middleware('throttle:5,1');

// Authenticated platform routes
Route::middleware('auth:platform')->group(function () {
    Route::get('/me', [PlatformAuthController::class, 'me']);
    Route::post('/logout', [PlatformAuthController::class, 'logout']);

    // Companies
    Route::middleware('platform.permission:manage_companies')->group(function () {
        Route::get('/companies', [PlatformCompanyController::class, 'index']);
        Route::put('/companies/{id}/suspend', [PlatformCompanyController::class, 'suspend']);
        Route::put('/companies/{id}/reactivate', [PlatformCompanyController::class, 'reactivate']);
    });

    // Company users (read-only supervision)
    Route::middleware('platform.permission:view_company_users')->group(function () {
        Route::get('/company-users', [PlatformCompanyUserController::class, 'index']);
    });

    // Platform users (CRUD)
    Route::middleware('platform.permission:manage_platform_users')->group(function () {
        Route::get('/platform-users', [PlatformUserController::class, 'index']);
        Route::post('/platform-users', [PlatformUserController::class, 'store']);
        Route::put('/platform-users/{id}', [PlatformUserController::class, 'update']);
        Route::delete('/platform-users/{id}', [PlatformUserController::class, 'destroy']);
    });

    // Roles (CRUD)
    Route::middleware('platform.permission:manage_roles')->group(function () {
        Route::get('/roles', [PlatformRoleController::class, 'index']);
        Route::post('/roles', [PlatformRoleController::class, 'store']);
        Route::put('/roles/{id}', [PlatformRoleController::class, 'update']);
        Route::delete('/roles/{id}', [PlatformRoleController::class, 'destroy']);
    });

    // Permissions (read-only)
    Route::middleware('platform.permission:manage_roles')->group(function () {
        Route::get('/permissions', [PlatformPermissionController::class, 'index']);
    });

    // Modules
    Route::middleware('platform.permission:manage_modules')->group(function () {
        Route::get('/modules', [PlatformModuleController::class, 'index']);
        Route::put('/modules/{key}/toggle', [PlatformModuleController::class, 'toggle']);
    });
});
