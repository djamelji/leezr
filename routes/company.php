<?php

use App\Company\Http\Controllers\CompanyController;
use App\Company\Http\Controllers\CompanyFieldActivationController;
use App\Company\Http\Controllers\CompanyFieldDefinitionController;
use App\Company\Http\Controllers\CompanyJobdomainController;
use App\Company\Http\Controllers\CompanyModuleController;
use App\Company\Http\Controllers\CompanyRoleController;
use App\Company\Http\Controllers\MemberCredentialController;
use App\Company\Http\Controllers\MembershipController;
use App\Company\Http\Controllers\ShipmentController;
use App\Company\Http\Controllers\UserProfileController;
use Illuminate\Support\Facades\Route;

// Company-scoped routes
// Middleware: auth:sanctum + company.context (applied in bootstrap/app.php)
// All routes here require X-Company-Id header
// Authorization: company.access:{ability},{key} (owner bypasses all)

// ─── Company settings ─────────────────────────────────────
Route::get('/company', [CompanyController::class, 'show']);
Route::put('/company', [CompanyController::class, 'update'])
    ->middleware('company.access:use-permission,settings.manage');

// ─── Members management ───────────────────────────────────
Route::get('/company/members', [MembershipController::class, 'index']);
Route::get('/company/members/{id}', [MembershipController::class, 'show']);
Route::post('/company/members', [MembershipController::class, 'store'])
    ->middleware('company.access:use-permission,members.invite');
Route::put('/company/members/{id}', [MembershipController::class, 'update'])
    ->middleware('company.access:use-permission,members.manage');
Route::delete('/company/members/{id}', [MembershipController::class, 'destroy'])
    ->middleware('company.access:use-permission,members.manage');

// Member credentials
Route::post('/company/members/{id}/reset-password', [MemberCredentialController::class, 'resetPassword'])
    ->middleware('company.access:use-permission,members.credentials');
Route::put('/company/members/{id}/password', [MemberCredentialController::class, 'setPassword'])
    ->middleware('company.access:use-permission,members.credentials');

// ─── Jobdomain ────────────────────────────────────────────
Route::get('/company/jobdomain', [CompanyJobdomainController::class, 'show']);
Route::put('/company/jobdomain', [CompanyJobdomainController::class, 'update'])
    ->middleware('company.access:use-permission,settings.manage');

// ─── Modules management ──────────────────────────────────
Route::get('/modules', [CompanyModuleController::class, 'index']);
Route::put('/modules/{key}/enable', [CompanyModuleController::class, 'enable'])
    ->middleware('company.access:use-permission,settings.manage');
Route::put('/modules/{key}/disable', [CompanyModuleController::class, 'disable'])
    ->middleware('company.access:use-permission,settings.manage');

// ─── Shipments (module-gated) ─────────────────────────────
Route::middleware('company.access:use-module,logistics_shipments')->group(function () {
    Route::get('/shipments', [ShipmentController::class, 'index']);
    Route::post('/shipments', [ShipmentController::class, 'store'])
        ->middleware('company.access:use-permission,shipments.create');
    Route::get('/shipments/{id}', [ShipmentController::class, 'show']);
    Route::put('/shipments/{id}/status', [ShipmentController::class, 'changeStatus'])
        ->middleware('company.access:use-permission,shipments.manage_status');
});

// ─── Field activations ────────────────────────────────────
Route::get('/company/field-activations', [CompanyFieldActivationController::class, 'index']);
Route::post('/company/field-activations', [CompanyFieldActivationController::class, 'upsert'])
    ->middleware('company.access:use-permission,settings.manage');

// ─── Custom field definitions ─────────────────────────────
Route::get('/company/field-definitions', [CompanyFieldDefinitionController::class, 'index']);
Route::post('/company/field-definitions', [CompanyFieldDefinitionController::class, 'store'])
    ->middleware('company.access:use-permission,settings.manage');
Route::put('/company/field-definitions/{id}', [CompanyFieldDefinitionController::class, 'update'])
    ->middleware('company.access:use-permission,settings.manage');
Route::delete('/company/field-definitions/{id}', [CompanyFieldDefinitionController::class, 'destroy'])
    ->middleware('company.access:use-permission,settings.manage');

// ─── Company roles (structure — manage-structure) ─────────
Route::get('/company/roles', [CompanyRoleController::class, 'index'])
    ->middleware('company.access:manage-structure');
Route::get('/company/permissions', [CompanyRoleController::class, 'permissionCatalog'])
    ->middleware('company.access:manage-structure');
Route::post('/company/roles', [CompanyRoleController::class, 'store'])
    ->middleware('company.access:manage-structure');
Route::put('/company/roles/{id}', [CompanyRoleController::class, 'update'])
    ->middleware('company.access:manage-structure');
Route::delete('/company/roles/{id}', [CompanyRoleController::class, 'destroy'])
    ->middleware('company.access:manage-structure');

// ─── User profile (personal data, no permission needed) ──
Route::get('/profile', [UserProfileController::class, 'show']);
Route::put('/profile', [UserProfileController::class, 'update']);
Route::put('/profile/password', [UserProfileController::class, 'updatePassword']);
Route::post('/profile/avatar', [UserProfileController::class, 'updateAvatar']);
