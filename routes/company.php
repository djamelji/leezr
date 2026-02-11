<?php

use App\Company\Http\Controllers\CompanyController;
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

// User profile (scoped to company context but personal data)
Route::get('/profile', [UserProfileController::class, 'show']);
Route::put('/profile', [UserProfileController::class, 'update']);
Route::put('/profile/password', [UserProfileController::class, 'updatePassword']);
Route::post('/profile/avatar', [UserProfileController::class, 'updateAvatar']);
