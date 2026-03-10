<?php

use App\Modules\Core\Dashboard\Http\CompanyDashboardLayoutController;
use App\Modules\Core\Dashboard\Http\CompanyDashboardWidgetController;
use App\Modules\Core\Dashboard\Http\OnboardingStatusController;
use App\Modules\Infrastructure\Realtime\Http\RealtimeStreamController;
use App\Modules\Infrastructure\Navigation\Http\NavController;
use App\Modules\Core\Billing\Http\BillingCheckoutController;
use App\Modules\Core\Billing\Http\CheckoutStatusController;
use App\Modules\Core\Billing\Http\CompanyBillingController;
use App\Modules\Core\Billing\Http\CompanyBillingTimelineController;
use App\Modules\Core\Billing\Http\CompanyPaymentMethodController;
use App\Modules\Core\Billing\Http\CompanyPaymentSetupController;
use App\Modules\Core\Billing\Http\CompanyPlanController;
use App\Modules\Core\Billing\Http\InvoiceBatchPayController;
use App\Modules\Core\Billing\Http\SubscriptionMutationController;
use App\Modules\Core\Members\Http\MemberCredentialController;
use App\Modules\Core\Members\Http\MembershipController;
use App\Modules\Core\Members\Http\UserProfileController;
use App\Modules\Core\Audit\Http\CompanyAuditLogController;
use App\Modules\Core\Modules\Http\CompanyModuleController;
use App\Modules\Core\Modules\Http\ModuleQuoteController;
use App\Modules\Core\Settings\Http\CompanyController;
use App\Modules\Core\Theme\Http\ThemePreferenceController;
use App\Modules\Core\Theme\Http\ThemeRoleVisibilityController;
use App\Modules\Core\Settings\Http\CompanyDocumentActivationController;
use App\Modules\Core\Settings\Http\CustomDocumentTypeController;
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

// ─── Theme preference (ADR-159, module-gated) ───────────
Route::middleware(['company.access:use-module,core.theme', 'company.access:use-permission,theme.manage'])->group(function () {
    Route::put('/theme-preference', [ThemePreferenceController::class, 'update']);
});

// ─── Theme role visibility (ADR-161) ──────────────
Route::middleware(['company.access:use-module,core.theme', 'company.access:manage-structure'])->group(function () {
    Route::get('/theme/role-visibility', [ThemeRoleVisibilityController::class, 'index']);
    Route::put('/theme/role-visibility', [ThemeRoleVisibilityController::class, 'update']);
});

// ─── Realtime SSE stream (ADR-125, no module gate) ────────
Route::get('/realtime/stream', RealtimeStreamController::class);

// ─── Company Dashboard (core, no module gate — ADR-149) ───
Route::get('/dashboard/widgets/catalog', [CompanyDashboardWidgetController::class, 'catalog']);
Route::post('/dashboard/widgets/data', [CompanyDashboardWidgetController::class, 'batchResolve']);
Route::get('/dashboard/layout', [CompanyDashboardLayoutController::class, 'show']);
Route::put('/dashboard/layout', [CompanyDashboardLayoutController::class, 'update'])
    ->middleware('company.access:manage-structure');
Route::get('/dashboard/suggestions', [CompanyDashboardLayoutController::class, 'suggestions']);
Route::get('/dashboard/onboarding', OnboardingStatusController::class);

// ─── Company plan (ADR-100, module-gated) ─────────────────
Route::middleware('company.access:use-module,core.billing')->group(function () {
    Route::put('/company/plan', [CompanyPlanController::class, 'update'])
        ->middleware('company.access:manage-structure');

    Route::post('/billing/checkout', BillingCheckoutController::class)
        ->middleware('company.access:manage-structure');
    Route::get('/billing/checkout/status', CheckoutStatusController::class);

    // Billing details (ADR-124, ADR-135 LOT4)
    Route::get('/billing/overview', [CompanyBillingController::class, 'overview']);
    Route::get('/billing/invoices', [CompanyBillingController::class, 'invoices']);
    Route::get('/billing/invoices/outstanding', [InvoiceBatchPayController::class, 'listOutstanding']);
    Route::get('/billing/invoices/{id}', [CompanyBillingController::class, 'invoiceDetail']);
    Route::get('/billing/subscription', [CompanyBillingController::class, 'subscription']);
    Route::delete('/billing/pending-subscription', [CompanyBillingController::class, 'dismissPendingSubscription'])
        ->middleware('company.access:manage-structure');
    Route::get('/billing/next-invoice-preview', [CompanyBillingController::class, 'nextInvoicePreview']);
    Route::get('/billing/plan-change-preview', [CompanyBillingController::class, 'planChangePreview']);
    Route::get('/billing/invoices/{id}/pdf', [CompanyBillingController::class, 'invoicePdf']);
    Route::get('/billing/timeline', CompanyBillingTimelineController::class);

    // Payment methods & invoice retry (ADR-225, manage-structure required)
    Route::post('/billing/setup-intent', [CompanyPaymentSetupController::class, 'createSetupIntent'])
        ->middleware('company.access:manage-structure');
    Route::post('/billing/confirm-setup-intent', [CompanyPaymentSetupController::class, 'confirmSetupIntent'])
        ->middleware('company.access:manage-structure');
    Route::get('/billing/saved-cards', [CompanyPaymentMethodController::class, 'savedCards']);
    Route::post('/billing/invoices/{id}/retry', [CompanyPaymentMethodController::class, 'retryInvoice'])
        ->middleware('company.access:manage-structure');
    Route::delete('/billing/saved-cards/{id}', [CompanyPaymentMethodController::class, 'deleteCard'])
        ->middleware('company.access:manage-structure');
    Route::put('/billing/saved-cards/{id}/default', [CompanyPaymentMethodController::class, 'setDefault'])
        ->middleware('company.access:manage-structure');

    // Batch invoice payment (ADR-257)
    Route::post('/billing/invoices/pay', [InvoiceBatchPayController::class, 'createPaymentIntent'])
        ->middleware('company.access:manage-structure');
    Route::post('/billing/invoices/pay/confirm', [InvoiceBatchPayController::class, 'confirmPayment'])
        ->middleware('company.access:manage-structure');

    // Subscription mutations (ADR-135 D1, manage-structure required)
    Route::post('/billing/plan-change', [SubscriptionMutationController::class, 'planChange'])
        ->middleware('company.access:manage-structure');
    Route::delete('/billing/plan-change', [SubscriptionMutationController::class, 'cancelPlanChange'])
        ->middleware('company.access:manage-structure');
    Route::put('/billing/subscription/cancel', [SubscriptionMutationController::class, 'cancel'])
        ->middleware('company.access:manage-structure');
    Route::post('/billing/pay-now', [SubscriptionMutationController::class, 'payNow'])
        ->middleware('company.access:manage-structure');
    Route::put('/billing/subscription/billing-day', [SubscriptionMutationController::class, 'setBillingDay'])
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

    // Company documents vault (ADR-174)
    Route::get('/company/documents', [\App\Modules\Core\Settings\Http\CompanyDocumentController::class, 'index']);
    Route::post('/company/documents/{code}', [\App\Modules\Core\Settings\Http\CompanyDocumentController::class, 'upload'])
        ->middleware('company.access:use-permission,settings.manage');
    Route::get('/company/documents/{code}/download', [\App\Modules\Core\Settings\Http\CompanyDocumentController::class, 'download']);

    // Document activation catalog (ADR-175)
    Route::get('/company/document-activations', [CompanyDocumentActivationController::class, 'index']);
    Route::put('/company/document-activations/{code}', [CompanyDocumentActivationController::class, 'upsert'])
        ->middleware('company.access:use-permission,settings.manage');

    // Custom document types (ADR-180)
    Route::post('/company/document-types/custom', [CustomDocumentTypeController::class, 'store'])
        ->middleware('company.access:use-permission,settings.manage');
    Route::put('/company/document-types/custom/{code}/archive', [CustomDocumentTypeController::class, 'archive'])
        ->middleware('company.access:use-permission,settings.manage');
    Route::delete('/company/document-types/custom/{code}', [CustomDocumentTypeController::class, 'destroy'])
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
    Route::get('/company/members/{id}/fields', [MembershipController::class, 'fields']);
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

    // Member documents (ADR-169 Phase 3)
    Route::get('/company/members/{id}/documents', [\App\Modules\Core\Members\Http\MemberDocumentController::class, 'index']);
    Route::post('/company/members/{id}/documents/{code}', [\App\Modules\Core\Members\Http\MemberDocumentController::class, 'upload'])
        ->middleware('company.access:use-permission,members.manage');
    Route::get('/company/members/{id}/documents/{code}/download', [\App\Modules\Core\Members\Http\MemberDocumentController::class, 'download']);
    Route::delete('/company/members/{id}/documents/{code}', [\App\Modules\Core\Members\Http\MemberDocumentController::class, 'destroy'])
        ->middleware('company.access:use-permission,members.manage');

    // Document review workflow (ADR-176)
    Route::put('/company/members/{id}/documents/{code}/review', [\App\Modules\Core\Members\Http\MemberDocumentController::class, 'review'])
        ->middleware('company.access:use-permission,members.manage');

    // Document requests (ADR-192)
    Route::post('/company/document-requests', [\App\Modules\Core\Members\Http\DocumentRequestController::class, 'store'])
        ->middleware('company.access:use-permission,members.manage');
    Route::post('/company/document-requests/batch', [\App\Modules\Core\Members\Http\DocumentRequestController::class, 'batchByRole'])
        ->middleware('company.access:use-permission,members.manage');
    Route::get('/company/document-requests/queue', [\App\Modules\Core\Members\Http\DocumentRequestController::class, 'queue'])
        ->middleware('company.access:use-permission,members.manage');

    // User profile (personal data, no additional permission needed)
    Route::get('/profile', [UserProfileController::class, 'show']);
    Route::put('/profile', [UserProfileController::class, 'update']);
    Route::put('/profile/password', [UserProfileController::class, 'updatePassword']);
    Route::post('/profile/avatar', [UserProfileController::class, 'updateAvatar']);

    // Self-document upload (ADR-173, no additional permission — self scope)
    Route::get('/profile/documents', [\App\Modules\Core\Members\Http\SelfDocumentController::class, 'index']);
    Route::post('/profile/documents/{code}', [\App\Modules\Core\Members\Http\SelfDocumentController::class, 'upload']);
    Route::get('/profile/documents/{code}/download', [\App\Modules\Core\Members\Http\SelfDocumentController::class, 'download']);
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
