<?php

use App\Company\Http\Controllers\CompanyController;
use App\Company\Http\Controllers\CompanyFieldActivationController;
use App\Company\Http\Controllers\CompanyFieldDefinitionController;
use App\Company\Http\Controllers\CompanyJobdomainController;
use App\Company\Http\Controllers\CompanyModuleController;
use App\Company\Http\Controllers\MembershipController;
use App\Company\Http\Controllers\ShipmentController;
use App\Company\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

// Company-scoped routes
// Middleware: auth:sanctum + company.context (applied in bootstrap/app.php)
// All routes here require X-Company-Id header

// Company settings
Route::get('/company', [CompanyController::class, 'show']);
Route::put('/company', [CompanyController::class, 'update'])
    ->middleware('company.role:admin');

// Members management
Route::get('/company/members', [MembershipController::class, 'index']);
Route::get('/company/members/{id}', [MembershipController::class, 'show']);
Route::post('/company/members', [MembershipController::class, 'store'])
    ->middleware('company.role:admin');
Route::put('/company/members/{id}', [MembershipController::class, 'update'])
    ->middleware('company.role:admin');
Route::delete('/company/members/{id}', [MembershipController::class, 'destroy'])
    ->middleware('company.role:admin');

// Jobdomain
Route::get('/company/jobdomain', [CompanyJobdomainController::class, 'show']);
Route::put('/company/jobdomain', [CompanyJobdomainController::class, 'update'])
    ->middleware('company.role:admin');

// Modules management
Route::get('/modules', [CompanyModuleController::class, 'index']);
Route::put('/modules/{key}/enable', [CompanyModuleController::class, 'enable'])
    ->middleware('company.role:admin');
Route::put('/modules/{key}/disable', [CompanyModuleController::class, 'disable'])
    ->middleware('company.role:admin');

// Shipments (module: logistics_shipments)
Route::middleware('module.active:logistics_shipments')->group(function () {
    Route::get('/shipments', [ShipmentController::class, 'index']);
    Route::post('/shipments', [ShipmentController::class, 'store'])
        ->middleware('company.role:admin');
    Route::get('/shipments/{id}', [ShipmentController::class, 'show']);
    Route::put('/shipments/{id}/status', [ShipmentController::class, 'changeStatus'])
        ->middleware('company.role:admin');
});

// Field activations (company + company_user scopes)
Route::get('/company/field-activations', [CompanyFieldActivationController::class, 'index']);
Route::post('/company/field-activations', [CompanyFieldActivationController::class, 'upsert'])
    ->middleware('company.role:admin');

// Custom field definitions (company-owned, gated by jobdomain.allow_custom_fields)
Route::get('/company/field-definitions', [CompanyFieldDefinitionController::class, 'index']);
Route::post('/company/field-definitions', [CompanyFieldDefinitionController::class, 'store'])
    ->middleware('company.role:admin');
Route::put('/company/field-definitions/{id}', [CompanyFieldDefinitionController::class, 'update'])
    ->middleware('company.role:admin');
Route::delete('/company/field-definitions/{id}', [CompanyFieldDefinitionController::class, 'destroy'])
    ->middleware('company.role:admin');

// User profile (scoped to company context but personal data)
Route::get('/profile', [UserProfileController::class, 'show']);
Route::put('/profile', [UserProfileController::class, 'update']);
Route::put('/profile/password', [UserProfileController::class, 'updatePassword']);
Route::post('/profile/avatar', [UserProfileController::class, 'updateAvatar']);
