<?php

use App\Modules\Platform\Companies\Http\CompanyController;
use App\Modules\Platform\Companies\Http\CompanyUserController;
use App\Modules\Platform\Fields\Http\FieldActivationController;
use App\Modules\Platform\Fields\Http\FieldDefinitionController;
use App\Modules\Platform\Jobdomains\Http\JobdomainController;
use App\Modules\Platform\Modules\Http\ModuleController;
use App\Modules\Platform\Settings\Http\SessionSettingsController;
use App\Modules\Platform\Settings\Http\TypographyController;
use App\Modules\Platform\Theme\Http\ThemeController;
use App\Modules\Platform\Roles\Http\PermissionController;
use App\Modules\Platform\Roles\Http\RoleController;
use App\Modules\Platform\Users\Http\UserController;
use App\Platform\Auth\PlatformAuthController;
use App\Platform\Auth\PlatformPasswordResetController;
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
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::put('/companies/{id}/suspend', [CompanyController::class, 'suspend']);
        Route::put('/companies/{id}/reactivate', [CompanyController::class, 'reactivate']);
    });

    // Company users (read-only supervision)
    Route::middleware('platform.permission:view_company_users')->group(function () {
        Route::get('/company-users', [CompanyUserController::class, 'index']);
    });

    // Platform users (CRUD)
    Route::middleware('platform.permission:manage_platform_users')->group(function () {
        Route::get('/platform-users', [UserController::class, 'index']);
        Route::post('/platform-users', [UserController::class, 'store']);
        Route::put('/platform-users/{id}', [UserController::class, 'update']);
        Route::delete('/platform-users/{id}', [UserController::class, 'destroy']);
    });

    // Platform user credentials (privileged)
    Route::middleware('platform.permission:manage_platform_user_credentials')->group(function () {
        Route::post('/platform-users/{id}/reset-password', [PlatformPasswordResetController::class, 'adminResetPassword']);
        Route::put('/platform-users/{id}/password', [UserController::class, 'setPassword']);
    });

    // Roles (CRUD)
    Route::middleware('platform.permission:manage_roles')->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{id}', [RoleController::class, 'update']);
        Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
    });

    // Permissions (read-only)
    Route::middleware('platform.permission:manage_roles')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
    });

    // Modules
    Route::middleware('platform.permission:manage_modules')->group(function () {
        Route::get('/modules', [ModuleController::class, 'index']);
        Route::put('/modules/{key}/toggle', [ModuleController::class, 'toggle']);
    });

    // Field Definitions + Activations
    Route::middleware('platform.permission:manage_field_definitions')->group(function () {
        Route::get('/field-definitions', [FieldDefinitionController::class, 'index']);
        Route::post('/field-definitions', [FieldDefinitionController::class, 'store']);
        Route::put('/field-definitions/{id}', [FieldDefinitionController::class, 'update']);
        Route::delete('/field-definitions/{id}', [FieldDefinitionController::class, 'destroy']);

        Route::get('/field-activations', [FieldActivationController::class, 'index']);
        Route::post('/field-activations', [FieldActivationController::class, 'upsert']);
    });

    // Platform user profile (show with dynamic fields)
    Route::middleware('platform.permission:manage_platform_users')->group(function () {
        Route::get('/platform-users/{id}', [UserController::class, 'show']);
    });

    // Theme Settings
    Route::middleware('platform.permission:manage_theme_settings')->group(function () {
        Route::get('/theme', [ThemeController::class, 'show']);
        Route::put('/theme', [ThemeController::class, 'update']);
    });

    // Typography Settings
    Route::middleware('platform.permission:manage_theme_settings')->group(function () {
        Route::get('/typography', [TypographyController::class, 'show']);
        Route::put('/typography', [TypographyController::class, 'update']);
        Route::post('/font-families', [TypographyController::class, 'createFamily']);
        Route::post('/font-families/{familyId}/fonts', [TypographyController::class, 'uploadFont']);
        Route::delete('/font-families/{familyId}/fonts/{fontId}', [TypographyController::class, 'deleteFont']);
        Route::delete('/font-families/{familyId}', [TypographyController::class, 'deleteFamily']);
    });

    // Session Settings
    Route::middleware('platform.permission:manage_session_settings')->group(function () {
        Route::get('/session-settings', [SessionSettingsController::class, 'show']);
        Route::put('/session-settings', [SessionSettingsController::class, 'update']);
    });

    // Job Domains (CRUD)
    Route::middleware('platform.permission:manage_jobdomains')->group(function () {
        Route::get('/jobdomains', [JobdomainController::class, 'index']);
        Route::get('/jobdomains/{id}', [JobdomainController::class, 'show']);
        Route::post('/jobdomains', [JobdomainController::class, 'store']);
        Route::put('/jobdomains/{id}', [JobdomainController::class, 'update']);
        Route::delete('/jobdomains/{id}', [JobdomainController::class, 'destroy']);
    });
});
