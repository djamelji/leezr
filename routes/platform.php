<?php

use App\Platform\Auth\PlatformAuthController;
use App\Platform\Auth\PlatformPasswordResetController;
use App\Platform\Http\Controllers\PlatformCompanyController;
use App\Platform\Http\Controllers\PlatformCompanyUserController;
use App\Platform\Http\Controllers\PlatformFieldActivationController;
use App\Platform\Http\Controllers\PlatformFieldDefinitionController;
use App\Platform\Http\Controllers\PlatformJobdomainController;
use App\Platform\Http\Controllers\PlatformModuleController;
use App\Platform\Http\Controllers\PlatformPermissionController;
use App\Platform\Http\Controllers\PlatformRoleController;
use App\Platform\Http\Controllers\PlatformUserController;
use Illuminate\Support\Facades\Route;

// Platform auth — public (throttled)
Route::post('/login', [PlatformAuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/forgot-password', [PlatformPasswordResetController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/reset-password', [PlatformPasswordResetController::class, 'resetPassword'])
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

    // Platform user credentials (privileged)
    Route::middleware('platform.permission:manage_platform_user_credentials')->group(function () {
        Route::post('/platform-users/{id}/reset-password', [PlatformPasswordResetController::class, 'adminResetPassword']);
        Route::put('/platform-users/{id}/password', [PlatformUserController::class, 'setPassword']);
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

    // Field Definitions + Activations
    Route::middleware('platform.permission:manage_field_definitions')->group(function () {
        Route::get('/field-definitions', [PlatformFieldDefinitionController::class, 'index']);
        Route::post('/field-definitions', [PlatformFieldDefinitionController::class, 'store']);
        Route::put('/field-definitions/{id}', [PlatformFieldDefinitionController::class, 'update']);
        Route::delete('/field-definitions/{id}', [PlatformFieldDefinitionController::class, 'destroy']);

        Route::get('/field-activations', [PlatformFieldActivationController::class, 'index']);
        Route::post('/field-activations', [PlatformFieldActivationController::class, 'upsert']);
    });

    // Platform user profile (show with dynamic fields)
    Route::middleware('platform.permission:manage_platform_users')->group(function () {
        Route::get('/platform-users/{id}', [PlatformUserController::class, 'show']);
    });

    // Job Domains (CRUD)
    Route::middleware('platform.permission:manage_jobdomains')->group(function () {
        Route::get('/jobdomains', [PlatformJobdomainController::class, 'index']);
        Route::get('/jobdomains/{id}', [PlatformJobdomainController::class, 'show']);
        Route::post('/jobdomains', [PlatformJobdomainController::class, 'store']);
        Route::put('/jobdomains/{id}', [PlatformJobdomainController::class, 'update']);
        Route::delete('/jobdomains/{id}', [PlatformJobdomainController::class, 'destroy']);
    });
});
