<?php

use App\Modules\Core\Dashboard\Http\CompanyDashboardLayoutController;
use App\Modules\Core\Dashboard\Http\CompanyDashboardWidgetController;
use App\Modules\Infrastructure\Realtime\Http\RealtimeStreamController;
use App\Modules\Infrastructure\Navigation\Http\NavController;
use App\Modules\Core\Billing\Http\BillingCheckoutController;
use App\Modules\Core\Billing\Http\CompanyBillingController;
use App\Modules\Core\Billing\Http\CompanyPlanController;
use App\Modules\Core\Billing\Http\SubscriptionMutationController;
use App\Modules\Core\Members\Http\MemberCredentialController;
use App\Modules\Core\Members\Http\MembershipController;
use App\Modules\Core\Members\Http\UserProfileController;
use App\Modules\Core\Audit\Http\CompanyAuditLogController;
use App\Modules\Core\Modules\Http\CompanyModuleController;
use App\Modules\Core\Modules\Http\ModuleQuoteController;
use App\Modules\Core\Settings\Http\CompanyController;
use App\Modules\Core\Settings\Http\CompanyLegalStructureController;
use App\Modules\Core\Settings\Http\CompanyFieldActivationController;
use App\Modules\Core\Settings\Http\CompanyFieldDefinitionController;
use App\Modules\Core\Jobdomain\Http\CompanyJobdomainController;
use App\Modules\Core\Roles\Http\CompanyRoleController;
use App\Modules\Logistics\Shipments\Http\MyDeliveryController;
use App\Modules\Logistics\Shipments\Http\ShipmentController;
use Illuminate\Support\Facades\Route;

// Company-scoped routes
// Middleware: auth:sanctum + company.context (applied in bootstrap/app.php)
// All routes here require X-Company-Id header
// Authorization: company.access:{ability},{key} (owner bypasses all)

// ─── Navigation (infrastructure-level, no module gate) ────
Route::get('/nav', [NavController::class, 'company']);

// ─── Realtime SSE stream (ADR-125, no module gate) ────────
Route::get('/realtime/stream', RealtimeStreamController::class);

// ─── Company Dashboard (core, no module gate — ADR-149) ───
Route::get('/dashboard/widgets/catalog', [CompanyDashboardWidgetController::class, 'catalog']);
Route::post('/dashboard/widgets/data', [CompanyDashboardWidgetController::class, 'batchResolve']);
Route::get('/dashboard/layout', [CompanyDashboardLayoutController::class, 'show']);
Route::put('/dashboard/layout', [CompanyDashboardLayoutController::class, 'update'])
    ->middleware('company.access:manage-structure');
Route::get('/dashboard/suggestions', [CompanyDashboardLayoutController::class, 'suggestions']);

// ─── Company plan (ADR-100, module-gated) ─────────────────
Route::middleware('company.access:use-module,core.billing')->group(function () {
    Route::put('/company/plan', [CompanyPlanController::class, 'update'])
        ->middleware('company.access:manage-structure');

    Route::post('/billing/checkout', BillingCheckoutController::class)
        ->middleware('company.access:manage-structure');

    // Billing details (ADR-124, ADR-135 LOT4)
    Route::get('/billing/overview', [CompanyBillingController::class, 'overview']);
    Route::get('/billing/invoices', [CompanyBillingController::class, 'invoices']);
    Route::get('/billing/invoices/{id}', [CompanyBillingController::class, 'invoiceDetail']);
    Route::get('/billing/payments', [CompanyBillingController::class, 'payments']);
    Route::get('/billing/wallet', [CompanyBillingController::class, 'wallet']);
    Route::get('/billing/subscription', [CompanyBillingController::class, 'subscription']);
    Route::get('/billing/payment-methods', [CompanyBillingController::class, 'paymentMethods']);
    Route::get('/billing/portal-url', [CompanyBillingController::class, 'portalUrl']);
    Route::get('/billing/invoices/{id}/pdf', [CompanyBillingController::class, 'invoicePdf']);

    // Subscription mutations (ADR-135 D1, manage-structure required)
    Route::post('/billing/plan-change', [SubscriptionMutationController::class, 'planChange'])
        ->middleware('company.access:manage-structure');
    Route::put('/billing/subscription/cancel', [SubscriptionMutationController::class, 'cancel'])
        ->middleware('company.access:manage-structure');
    Route::post('/billing/pay-now', [SubscriptionMutationController::class, 'payNow'])
        ->middleware('company.access:manage-structure');
});

// ─── Company settings (module-gated) ─────────────────────
Route::middleware('company.access:use-module,core.settings')->group(function () {
    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'update'])
        ->middleware('company.access:use-permission,settings.manage');

    // Legal structure (ADR-104)
    Route::get('/company/legal-structure', [CompanyLegalStructureController::class, 'show']);
    Route::put('/company/legal-structure', [CompanyLegalStructureController::class, 'updateLegalStatus'])
        ->middleware('company.access:use-permission,settings.manage');

    // Field activations
    Route::get('/company/field-activations', [CompanyFieldActivationController::class, 'index']);
    Route::post('/company/field-activations', [CompanyFieldActivationController::class, 'upsert'])
        ->middleware('company.access:use-permission,settings.manage');

    // Custom field definitions
    Route::get('/company/field-definitions', [CompanyFieldDefinitionController::class, 'index']);
    Route::post('/company/field-definitions', [CompanyFieldDefinitionController::class, 'store'])
        ->middleware('company.access:use-permission,settings.manage');
    Route::put('/company/field-definitions/{id}', [CompanyFieldDefinitionController::class, 'update'])
        ->middleware('company.access:use-permission,settings.manage');
    Route::delete('/company/field-definitions/{id}', [CompanyFieldDefinitionController::class, 'destroy'])
        ->middleware('company.access:use-permission,settings.manage');

});

// ─── Company roles (module-gated, permission-based) ───────
Route::middleware('company.access:use-module,core.roles')->group(function () {
    Route::get('/company/roles', [CompanyRoleController::class, 'index'])
        ->middleware('company.access:use-permission,roles.view');
    Route::get('/company/permissions', [CompanyRoleController::class, 'permissionCatalog'])
        ->middleware('company.access:use-permission,roles.view');
    Route::post('/company/roles', [CompanyRoleController::class, 'store'])
        ->middleware('company.access:use-permission,roles.manage');
    Route::put('/company/roles/{id}', [CompanyRoleController::class, 'update'])
        ->middleware('company.access:use-permission,roles.manage');
    Route::delete('/company/roles/{id}', [CompanyRoleController::class, 'destroy'])
        ->middleware('company.access:use-permission,roles.manage');
});

// ─── Company jobdomain (module-gated, permission-based) ──
Route::middleware('company.access:use-module,core.jobdomain')->group(function () {
    Route::get('/company/jobdomain', [CompanyJobdomainController::class, 'show'])
        ->middleware('company.access:use-permission,jobdomain.view');
    Route::put('/company/jobdomain', [CompanyJobdomainController::class, 'update'])
        ->middleware('company.access:use-permission,jobdomain.manage');
});

// ─── Members management (module-gated) ───────────────────
Route::middleware('company.access:use-module,core.members')->group(function () {
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

    // User profile (personal data, no additional permission needed)
    Route::get('/profile', [UserProfileController::class, 'show']);
    Route::put('/profile', [UserProfileController::class, 'update']);
    Route::put('/profile/password', [UserProfileController::class, 'updatePassword']);
    Route::post('/profile/avatar', [UserProfileController::class, 'updateAvatar']);
});

// ─── Modules management (module-gated) ───────────────────
Route::middleware('company.access:use-module,core.modules')->group(function () {
    Route::get('/modules', [CompanyModuleController::class, 'index']);
    Route::put('/modules/{key}/enable', [CompanyModuleController::class, 'enable'])
        ->middleware('company.access:use-permission,modules.manage');
    Route::put('/modules/{key}/disable', [CompanyModuleController::class, 'disable'])
        ->middleware('company.access:use-permission,modules.manage');
    Route::get('/modules/{key}/settings', [CompanyModuleController::class, 'getSettings']);
    Route::put('/modules/{key}/settings', [CompanyModuleController::class, 'updateSettings'])
        ->middleware('company.access:use-permission,modules.manage');
    Route::get('/modules/quote', ModuleQuoteController::class);
});

// ─── Audit log (module-gated, admin permission) ─────────
Route::middleware('company.access:use-module,core.audit')->group(function () {
    Route::get('/audit', [CompanyAuditLogController::class, 'index'])
        ->middleware('company.access:use-permission,audit.view');
});

// ─── Shipments (module-gated) ─────────────────────────────
Route::middleware('company.access:use-module,logistics_shipments')->group(function () {
    // Management routes
    Route::get('/shipments', [ShipmentController::class, 'index'])
        ->middleware('company.access:use-permission,shipments.view');
    Route::post('/shipments', [ShipmentController::class, 'store'])
        ->middleware('company.access:use-permission,shipments.create');
    Route::get('/shipments/{id}', [ShipmentController::class, 'show'])
        ->middleware('company.access:use-permission,shipments.view');
    Route::put('/shipments/{id}/status', [ShipmentController::class, 'changeStatus'])
        ->middleware('company.access:use-permission,shipments.manage_status');
    Route::post('/shipments/{id}/assign', [ShipmentController::class, 'assign'])
        ->middleware('company.access:use-permission,shipments.assign');

    // Driver routes (strictly separated)
    Route::get('/my-deliveries', [MyDeliveryController::class, 'index'])
        ->middleware('company.access:use-permission,shipments.view_own');
    Route::get('/my-deliveries/{id}', [MyDeliveryController::class, 'show'])
        ->middleware('company.access:use-permission,shipments.view_own');
    Route::put('/my-deliveries/{id}/status', [MyDeliveryController::class, 'updateStatus'])
        ->middleware('company.access:use-permission,shipments.manage_status');
});
