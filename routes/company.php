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
use App\Modules\Core\Modules\Http\ModuleDeactivationPreviewController;
use App\Modules\Core\Modules\Http\ModuleQuoteController;
use App\Modules\Core\Notifications\Http\NotificationController;
use App\Modules\Core\Notifications\Http\NotificationPreferenceController;
use App\Modules\Infrastructure\Auth\Http\TwoFactorController;
use App\Modules\Core\Settings\Http\CompanyController;
use App\Modules\Core\Theme\Http\ThemePreferenceController;
use App\Modules\Core\Theme\Http\ThemeRoleVisibilityController;
use App\Modules\Core\Documents\Http\CompanyDocumentActivationController;
use App\Modules\Core\Documents\Http\CompanyDocumentController;
use App\Modules\Core\Documents\Http\CustomDocumentTypeController;
use App\Modules\Core\Documents\Http\DocumentComplianceController;
use App\Modules\Core\Documents\Http\DocumentSettingController;
use App\Modules\Core\Documents\Http\DocumentRequestController;
use App\Modules\Core\Documents\Http\AiQuotaController;
use App\Modules\Core\Documents\Http\MemberDocumentAiSuggestionController;
use App\Modules\Core\Documents\Http\MemberDocumentController;
use App\Modules\Core\Documents\Http\SelfDocumentController;
use App\Modules\Core\Settings\Http\CompanyLegalStructureController;
use App\Modules\Core\Settings\Http\CompanyFieldActivationController;
use App\Modules\Core\Settings\Http\CompanyFieldDefinitionController;
use App\Modules\Core\Jobdomain\Http\CompanyJobdomainController;
use App\Modules\Core\Roles\Http\CompanyRoleController;
use App\Modules\Core\Support\Http\SupportTicketController;
use App\Modules\Core\Support\Http\SupportMessageController;
use App\Modules\Logistics\Shipments\Http\MyDeliveryController;
use App\Modules\Logistics\Shipments\Http\ShipmentController;
use Illuminate\Support\Facades\Route;

// Company-scoped routes
// Middleware: auth:sanctum + company.context (applied in bootstrap/app.php)
// All routes here require X-Company-Id header
// Authorization: company.access:{ability},{key} (owner bypasses all)

// ─── Navigation (infrastructure-level, no module gate) ────
Route::get('/nav', [NavController::class, 'company']);

// ─── Feature Flags (resolved for current company, no module gate) ──
Route::get('/feature-flags', \App\Modules\Core\FeatureFlag\Http\CompanyFeatureFlagController::class);

// ─── Theme preference (ADR-159, module-gated) ───────────
Route::middleware(['company.access:use-module,core.theme', 'company.access:use-permission,theme.manage'])->group(function () {
    Route::put('/theme-preference', [ThemePreferenceController::class, 'update']);
});

// ─── Theme role visibility (ADR-161, ADR-371: PUT requires roles.manage) ──────────────
Route::middleware(['company.access:use-module,core.theme', 'company.access:manage-structure'])->group(function () {
    Route::get('/theme/role-visibility', [ThemeRoleVisibilityController::class, 'index']);
    Route::put('/theme/role-visibility', [ThemeRoleVisibilityController::class, 'update'])
        ->middleware('company.access:use-permission,roles.manage');
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
Route::get('/dashboard/onboarding', [OnboardingStatusController::class, '__invoke']);
Route::post('/dashboard/onboarding/dismiss', [OnboardingStatusController::class, 'dismiss']);
Route::post('/dashboard/onboarding/reopen', [OnboardingStatusController::class, 'reopen']);

// ─── Notification inbox (core — always active) ────────────
Route::get('/notifications', [NotificationController::class, 'index']);
Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

// ─── Notification preferences ─────────────────────────────
Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'index']);
Route::put('/notifications/preferences', [NotificationPreferenceController::class, 'update']);

// ─── Two-Factor Authentication (ADR-351) ──────────────────
Route::post('/2fa/enable', [TwoFactorController::class, 'enable']);
Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm']);
Route::delete('/2fa', [TwoFactorController::class, 'disable']);
Route::post('/2fa/backup-codes', [TwoFactorController::class, 'regenerateBackupCodes']);
Route::get('/2fa/status', [TwoFactorController::class, 'status']);

// ─── Company plan & billing (ADR-100, ADR-371: billing.manage on ALL routes) ────
Route::middleware(['company.access:use-module,core.billing', 'company.access:use-permission,billing.manage', 'throttle:60,1'])->group(function () {
    // Plan change (manage-structure + billing.manage)
    Route::put('/company/plan', [CompanyPlanController::class, 'update'])
        ->middleware('company.access:manage-structure');

    // Checkout (manage-structure + billing.manage)
    Route::post('/billing/checkout', BillingCheckoutController::class)
        ->middleware('company.access:manage-structure');
    Route::get('/billing/checkout/status', CheckoutStatusController::class);

    // Billing details (ADR-124, ADR-135 LOT4 — billing.manage required for all reads)
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

    // Payment methods & invoice retry (ADR-225, manage-structure + billing.manage)
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
    Route::put('/billing/saved-cards/{id}/debit-day', [CompanyPaymentMethodController::class, 'setDebitDay'])
        ->middleware('company.access:manage-structure');

    // Batch invoice payment (ADR-257, manage-structure + billing.manage)
    Route::post('/billing/invoices/pay', [InvoiceBatchPayController::class, 'createPaymentIntent'])
        ->middleware('company.access:manage-structure');
    Route::post('/billing/invoices/pay/confirm', [InvoiceBatchPayController::class, 'confirmPayment'])
        ->middleware('company.access:manage-structure');

    // Subscription mutations (ADR-135 D1, manage-structure + billing.manage)
    Route::post('/billing/plan-change', [SubscriptionMutationController::class, 'planChange'])
        ->middleware('company.access:manage-structure');
    Route::delete('/billing/plan-change', [SubscriptionMutationController::class, 'cancelPlanChange'])
        ->middleware('company.access:manage-structure');
    Route::get('/billing/subscription/cancel-preview', [SubscriptionMutationController::class, 'cancelPreview'])
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

    // User profile (personal data, no additional permission needed)
    Route::get('/profile', [UserProfileController::class, 'show']);
    Route::put('/profile', [UserProfileController::class, 'update']);
    Route::put('/profile/password', [UserProfileController::class, 'updatePassword']);
    Route::post('/profile/avatar', [UserProfileController::class, 'updateAvatar']);
});

// ─── Documents governance (module-gated, ADR-389) ─────────
Route::middleware('company.access:use-module,core.documents')->group(function () {
    // Company documents vault (ADR-174)
    Route::get('/company/documents', [CompanyDocumentController::class, 'index'])
        ->middleware('company.access:use-permission,documents.view');
    Route::post('/company/documents/{code}', [CompanyDocumentController::class, 'upload'])
        ->middleware('company.access:use-permission,documents.manage');
    Route::get('/company/documents/{code}/download', [CompanyDocumentController::class, 'download'])
        ->middleware('company.access:use-permission,documents.view');
    Route::delete('/company/documents/{code}', [CompanyDocumentController::class, 'destroy'])
        ->middleware('company.access:use-permission,documents.manage');

    // Document compliance dashboard (ADR-387)
    Route::get('/company/documents/compliance', [DocumentComplianceController::class, 'index'])
        ->middleware('company.access:use-permission,documents.view');
    Route::get('/company/documents/compliance/export', [DocumentComplianceController::class, 'exportCsv'])
        ->middleware('company.access:use-permission,documents.manage');

    // Document activity feed (ADR-396)
    Route::get('/company/documents/activity', [DocumentComplianceController::class, 'activity'])
        ->middleware('company.access:use-permission,documents.view');

    // Document activation catalog (ADR-175)
    Route::get('/company/document-activations', [CompanyDocumentActivationController::class, 'index'])
        ->middleware('company.access:use-permission,documents.view');
    Route::put('/company/document-activations/{code}', [CompanyDocumentActivationController::class, 'upsert'])
        ->middleware('company.access:use-permission,documents.configure');

    // Document automation settings (ADR-397)
    Route::get('/company/document-settings', [DocumentSettingController::class, 'show'])
        ->middleware('company.access:use-permission,documents.configure');
    Route::put('/company/document-settings', [DocumentSettingController::class, 'update'])
        ->middleware('company.access:use-permission,documents.configure');

    // AI quota (ADR-436)
    Route::get('/company/ai-quota/{moduleKey}', [AiQuotaController::class, 'show'])
        ->middleware('company.access:use-permission,documents.view');

    // Custom document types (ADR-180)
    Route::post('/company/document-types/custom', [CustomDocumentTypeController::class, 'store'])
        ->middleware('company.access:use-permission,documents.configure');
    Route::put('/company/document-types/custom/{code}', [CustomDocumentTypeController::class, 'update'])
        ->middleware('company.access:use-permission,documents.configure');
    Route::put('/company/document-types/custom/{code}/archive', [CustomDocumentTypeController::class, 'archive'])
        ->middleware('company.access:use-permission,documents.configure');
    Route::delete('/company/document-types/custom/{code}', [CustomDocumentTypeController::class, 'destroy'])
        ->middleware('company.access:use-permission,documents.configure');

    // Member documents (ADR-169)
    Route::get('/company/members/{id}/documents', [MemberDocumentController::class, 'index'])
        ->middleware('company.access:use-permission,documents.view');
    Route::post('/company/members/{id}/documents/{code}', [MemberDocumentController::class, 'upload'])
        ->middleware('company.access:use-permission,documents.manage');
    Route::get('/company/members/{id}/documents/{code}/download', [MemberDocumentController::class, 'download'])
        ->middleware('company.access:use-permission,documents.view');
    Route::delete('/company/members/{id}/documents/{code}', [MemberDocumentController::class, 'destroy'])
        ->middleware('company.access:use-permission,documents.manage');

    // Document review workflow (ADR-176)
    Route::put('/company/members/{id}/documents/{code}/review', [MemberDocumentController::class, 'review'])
        ->middleware('company.access:use-permission,documents.manage');

    // ADR-422: Retry AI analysis for failed documents
    Route::post('/company/members/{id}/documents/{code}/retry-ai', [MemberDocumentController::class, 'retryAi'])
        ->middleware('company.access:use-permission,documents.manage');

    // ADR-426: Apply AI suggestions to member profile
    Route::post('/company/members/{id}/documents/{code}/apply-suggestions', [MemberDocumentAiSuggestionController::class, 'apply'])
        ->middleware('company.access:use-permission,documents.manage');

    // Document requests (ADR-192)
    Route::post('/company/document-requests', [DocumentRequestController::class, 'store'])
        ->middleware('company.access:use-permission,documents.manage');
    Route::post('/company/document-requests/batch', [DocumentRequestController::class, 'batchByRole'])
        ->middleware('company.access:use-permission,documents.manage');
    Route::get('/company/document-requests/queue', [DocumentRequestController::class, 'queue'])
        ->middleware('company.access:use-permission,documents.manage');
    Route::get('/company/document-requests/roles', [DocumentRequestController::class, 'batchRoles'])
        ->middleware('company.access:use-permission,documents.manage');

    Route::put('/company/document-requests/{id}/cancel', [DocumentRequestController::class, 'cancel'])
        ->middleware('company.access:use-permission,documents.manage');

    Route::put('/company/document-requests/{id}/remind', [DocumentRequestController::class, 'remind'])
        ->middleware('company.access:use-permission,documents.manage');
    Route::post('/company/document-requests/bulk-action', [DocumentRequestController::class, 'bulkAction'])
        ->middleware('company.access:use-permission,documents.manage');

    // Self-document upload (ADR-173, no additional permission — self scope)
    Route::get('/profile/documents', [SelfDocumentController::class, 'index']);
    Route::post('/profile/documents/{code}', [SelfDocumentController::class, 'upload']);
    Route::get('/profile/documents/{code}/download', [SelfDocumentController::class, 'download']);
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
    Route::get('/modules/{key}/deactivation-preview', ModuleDeactivationPreviewController::class);
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

// ── Support Tickets ─────────────────────────────────────────
Route::middleware('company.access:use-module,core.support')->group(function () {
    Route::get('/support/tickets', [SupportTicketController::class, 'index'])
        ->middleware('company.access:use-permission,support.view');
    Route::post('/support/tickets', [SupportTicketController::class, 'store'])
        ->middleware('company.access:use-permission,support.create');
    Route::get('/support/tickets/{id}', [SupportTicketController::class, 'show'])
        ->middleware('company.access:use-permission,support.view');
    Route::get('/support/tickets/{id}/messages', [SupportMessageController::class, 'index'])
        ->middleware('company.access:use-permission,support.view');
    Route::post('/support/tickets/{id}/messages', [SupportMessageController::class, 'store'])
        ->middleware('company.access:use-permission,support.create');
});

// ── Workflow Rules (ADR-437) ─────────────────────────────────
Route::middleware('company.access:use-module,core.workflows')->group(function () {
    Route::middleware('company.access:use-permission,automations.view')->group(function () {
        Route::get('/workflows', [\App\Modules\Core\Automations\Http\WorkflowRuleController::class, 'index']);
        Route::get('/workflows/triggers', [\App\Modules\Core\Automations\Http\WorkflowRuleController::class, 'triggers']);
        Route::get('/workflows/{workflowRule}', [\App\Modules\Core\Automations\Http\WorkflowRuleController::class, 'show']);
        Route::get('/workflows/{workflowRule}/logs', [\App\Modules\Core\Automations\Http\WorkflowRuleController::class, 'logs']);
    });
    Route::middleware('company.access:use-permission,automations.manage')->group(function () {
        Route::post('/workflows', [\App\Modules\Core\Automations\Http\WorkflowRuleController::class, 'store']);
        Route::put('/workflows/{workflowRule}', [\App\Modules\Core\Automations\Http\WorkflowRuleController::class, 'update']);
        Route::delete('/workflows/{workflowRule}', [\App\Modules\Core\Automations\Http\WorkflowRuleController::class, 'destroy']);
    });
});

// ── Workforce Self-Service (P0 security: auto-resolve employee from auth) ──
// Module-gated but no specific permission — any company member with an active employee record can access
Route::prefix('workforce/me')->middleware('company.access:use-module,workforce')->group(function () {
    Route::get('/', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'profile']);
    Route::get('/today', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'today']);
    Route::post('/clock-in', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'clockIn']);
    Route::post('/clock-out', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'clockOut']);
    Route::post('/break/start', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'startBreak']);
    Route::post('/break/end', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'endBreak']);
    Route::get('/leaves', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'leaves']);
    Route::post('/leaves', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'createLeave']);
    Route::get('/leave-balances', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'leaveBalances']);
    Route::get('/documents', [\App\Modules\Workforce\Employees\Http\EmployeeSelfController::class, 'documents']);
});

// ── Workforce Dashboard ──────────────────────────────────────
Route::prefix('workforce')->middleware('company.access:use-module,workforce')->group(function () {
    // ANO-017: company-wide workforce statistics are management data, not self-service.
    // Was module-only (any employee could read it); now requires workforce.view (owner bypasses).
    Route::get('dashboard/statistics', [\App\Modules\Workforce\Employees\Http\WorkforceDashboardController::class, 'statistics'])
        ->middleware('company.access:use-permission,workforce.view');
});

// ── Workforce ──────────────────────────────────────────────────
Route::prefix('workforce')->middleware('company.access:use-module,workforce')->group(function () {
    // ANO-014: these are MANAGEMENT endpoints. They were gated by module access only, so any
    // workforce-module member (e.g. a self-service employee) could create employees, generate
    // and approve timesheets, approve leave, etc. via the API — the frontend v-can merely hid
    // the buttons. Each route now also enforces the matching granular permission (mirrors v-can).
    // Owner bypasses all permission checks; self-service stays under the separate /workforce/me group.
    $perm = fn (string $key) => 'company.access:use-permission,'.$key;

    // Departments — view to read, manage to mutate
    Route::get('departments', [\App\Modules\Workforce\Employees\Http\DepartmentController::class, 'index'])->middleware($perm('workforce.view'));
    Route::post('departments', [\App\Modules\Workforce\Employees\Http\DepartmentController::class, 'store'])->middleware($perm('workforce.manage'));
    Route::put('departments/{id}', [\App\Modules\Workforce\Employees\Http\DepartmentController::class, 'update'])->middleware($perm('workforce.manage'));
    Route::delete('departments/{id}', [\App\Modules\Workforce\Employees\Http\DepartmentController::class, 'destroy'])->middleware($perm('workforce.manage'));

    // Job Roles
    Route::get('job-roles', [\App\Modules\Workforce\Employees\Http\JobRoleController::class, 'index'])->middleware($perm('workforce.view'));
    Route::post('job-roles', [\App\Modules\Workforce\Employees\Http\JobRoleController::class, 'store'])->middleware($perm('workforce.manage'));
    Route::put('job-roles/{id}', [\App\Modules\Workforce\Employees\Http\JobRoleController::class, 'update'])->middleware($perm('workforce.manage'));
    Route::delete('job-roles/{id}', [\App\Modules\Workforce\Employees\Http\JobRoleController::class, 'destroy'])->middleware($perm('workforce.manage'));

    // Employees — view to read, manage to mutate
    Route::get('employees/summary', [\App\Modules\Workforce\Employees\Http\EmployeeController::class, 'summary'])->middleware($perm('workforce.view'));
    Route::get('employees', [\App\Modules\Workforce\Employees\Http\EmployeeController::class, 'index'])->middleware($perm('workforce.view'));
    Route::post('employees', [\App\Modules\Workforce\Employees\Http\EmployeeController::class, 'store'])->middleware($perm('workforce.manage'));
    Route::get('employees/{id}', [\App\Modules\Workforce\Employees\Http\EmployeeController::class, 'show'])->middleware($perm('workforce.view'));
    Route::put('employees/{id}', [\App\Modules\Workforce\Employees\Http\EmployeeController::class, 'update'])->middleware($perm('workforce.manage'));
    Route::post('employees/{id}/terminate', [\App\Modules\Workforce\Employees\Http\EmployeeController::class, 'terminate'])->middleware($perm('workforce.manage'));

    // Contracts — view to read, contracts to create/activate
    Route::get('employees/{employeeId}/contracts', [\App\Modules\Workforce\Employees\Http\ContractController::class, 'index'])->middleware($perm('workforce.view'));
    Route::post('employees/{employeeId}/contracts', [\App\Modules\Workforce\Employees\Http\ContractController::class, 'store'])->middleware($perm('workforce.contracts'));
    Route::post('employees/{employeeId}/contracts/{contractId}/activate', [\App\Modules\Workforce\Employees\Http\ContractController::class, 'activate'])->middleware($perm('workforce.contracts'));

    Route::get('conventions', [\App\Modules\Workforce\Employees\Http\ContractController::class, 'conventions'])->middleware($perm('workforce.view'));

    // Timesheets — view to read, time_manage to generate, submit/approve per action, time_approve to lock
    Route::get('timesheets/statistics', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'statistics'])->middleware($perm('workforce.timesheet_view'));
    Route::get('timesheets', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'index'])->middleware($perm('workforce.timesheet_view'));
    Route::post('timesheets', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'generate'])->middleware($perm('workforce.time_manage'));
    Route::get('timesheets/{id}', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'show'])->middleware($perm('workforce.timesheet_view'));
    Route::post('timesheets/{id}/submit', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'submit'])->middleware($perm('workforce.timesheet_submit'));
    Route::post('timesheets/{id}/approve', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'approve'])->middleware($perm('workforce.timesheet_approve'));
    Route::post('timesheets/{id}/reject', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'reject'])->middleware($perm('workforce.timesheet_approve'));
    Route::post('timesheets/{id}/reopen', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'reopen'])->middleware($perm('workforce.time_manage'));
    Route::post('timesheets/{id}/lock', [\App\Modules\Workforce\Employees\Http\TimesheetController::class, 'lock'])->middleware($perm('workforce.time_approve'));

    // Time Entries — view to read, time_manage to mutate
    Route::get('time-entries', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'index'])->middleware($perm('workforce.timesheet_view'));
    Route::get('time-entries/company', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'companyIndex'])->middleware($perm('workforce.timesheet_view'));
    Route::get('time-entries/active', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'active'])->middleware($perm('workforce.timesheet_view'));
    Route::get('time-entries/anomalies', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'anomalies'])->middleware($perm('workforce.timesheet_view'));
    Route::post('time-entries', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'store'])->middleware($perm('workforce.time_manage'));
    Route::put('time-entries/{id}', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'update'])->middleware($perm('workforce.time_manage'));
    Route::delete('time-entries/{id}', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'destroy'])->middleware($perm('workforce.time_manage'));
    Route::post('time-entries/clock-in', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'clockIn'])->middleware($perm('workforce.time_manage'));
    Route::post('time-entries/{id}/clock-out', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'clockOut'])->middleware($perm('workforce.time_manage'));
    Route::post('time-entries/{id}/break/start', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'startBreak'])->middleware($perm('workforce.time_manage'));
    Route::post('time-entries/{id}/break/end', [\App\Modules\Workforce\Employees\Http\TimeEntryController::class, 'endBreak'])->middleware($perm('workforce.time_manage'));

    // Leave — leave_request to view/create, leave_approve to approve/reject/cancel, leave_manage for types
    Route::get('leaves/types', [\App\Modules\Workforce\Employees\Http\LeaveTypeController::class, 'index'])->middleware($perm('workforce.leave_request'));
    Route::post('leaves/types', [\App\Modules\Workforce\Employees\Http\LeaveTypeController::class, 'store'])->middleware($perm('workforce.leave_manage'));
    Route::put('leaves/types/{id}', [\App\Modules\Workforce\Employees\Http\LeaveTypeController::class, 'update'])->middleware($perm('workforce.leave_manage'));
    Route::delete('leaves/types/{id}', [\App\Modules\Workforce\Employees\Http\LeaveTypeController::class, 'destroy'])->middleware($perm('workforce.leave_manage'));
    Route::get('leaves/calendar', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'calendar'])->middleware($perm('workforce.leave_request'));
    Route::get('leaves/statistics', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'statistics'])->middleware($perm('workforce.leave_request'));
    Route::get('leaves', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'index'])->middleware($perm('workforce.leave_request'));
    Route::post('leaves', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'store'])->middleware($perm('workforce.leave_request'));
    Route::get('leaves/{id}', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'show'])->middleware($perm('workforce.leave_request'));
    Route::post('leaves/{id}/approve', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'approve'])->middleware($perm('workforce.leave_approve'));
    Route::post('leaves/{id}/reject', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'reject'])->middleware($perm('workforce.leave_approve'));
    Route::post('leaves/{id}/cancel', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'cancel'])->middleware($perm('workforce.leave_approve'));
    Route::get('employees/{employeeId}/leave-balances', [\App\Modules\Workforce\Employees\Http\LeaveController::class, 'balances'])->middleware($perm('workforce.leave_request'));
});

// ── Workforce Planning ────────────────────────────────────────
Route::prefix('workforce/planning')->middleware('company.access:use-module,workforce_planning')->group(function () {
    // ANO-014: management endpoints also enforce the granular permission (mirrors v-can).
    $perm = fn (string $key) => 'company.access:use-permission,'.$key;

    // Work Locations
    Route::get('locations', [\App\Modules\Workforce\Planning\Http\WorkLocationController::class, 'index'])->middleware($perm('workforce.planning_view'));
    Route::post('locations', [\App\Modules\Workforce\Planning\Http\WorkLocationController::class, 'store'])->middleware($perm('workforce.planning_manage'));
    Route::put('locations/{id}', [\App\Modules\Workforce\Planning\Http\WorkLocationController::class, 'update'])->middleware($perm('workforce.planning_manage'));
    Route::delete('locations/{id}', [\App\Modules\Workforce\Planning\Http\WorkLocationController::class, 'destroy'])->middleware($perm('workforce.planning_manage'));

    // Schedule Templates
    Route::get('templates', [\App\Modules\Workforce\Planning\Http\ScheduleTemplateController::class, 'index'])->middleware($perm('workforce.planning_view'));
    Route::post('templates', [\App\Modules\Workforce\Planning\Http\ScheduleTemplateController::class, 'store'])->middleware($perm('workforce.planning_manage'));
    Route::put('templates/{id}', [\App\Modules\Workforce\Planning\Http\ScheduleTemplateController::class, 'update'])->middleware($perm('workforce.planning_manage'));
    Route::delete('templates/{id}', [\App\Modules\Workforce\Planning\Http\ScheduleTemplateController::class, 'destroy'])->middleware($perm('workforce.planning_manage'));

    // Shifts
    Route::get('shifts/week', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'week'])->middleware($perm('workforce.planning_view'));
    Route::get('shifts/calendar', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'calendar'])->middleware($perm('workforce.planning_view'));
    Route::get('shifts/statistics', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'statistics'])->middleware($perm('workforce.planning_view'));
    Route::get('shifts', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'index'])->middleware($perm('workforce.planning_view'));
    Route::post('shifts', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'store'])->middleware($perm('workforce.planning_manage'));
    Route::post('shifts/publish', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'publish'])->middleware($perm('workforce.planning_publish'));
    Route::post('shifts/bulk', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'bulkCreate'])->middleware($perm('workforce.planning_manage'));
    Route::put('shifts/{id}', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'update'])->middleware($perm('workforce.planning_manage'));
    Route::delete('shifts/{id}', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'destroy'])->middleware($perm('workforce.planning_manage'));
    Route::post('shifts/{id}/cancel', [\App\Modules\Workforce\Planning\Http\ShiftController::class, 'cancel'])->middleware($perm('workforce.planning_manage'));
});

// ── Workforce Payroll ────────────────────────────────────────
Route::prefix('workforce/payroll')->middleware('company.access:use-module,workforce_payroll')->group(function () {
    // ANO-014: prepare to read/compute, validate to validate, export to export.
    $perm = fn (string $key) => 'company.access:use-permission,'.$key;

    // Payroll Runs
    Route::get('statistics', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'statistics'])->middleware($perm('workforce.payroll_prepare'));
    Route::get('/', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'index'])->middleware($perm('workforce.payroll_prepare'));
    Route::post('/', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'store'])->middleware($perm('workforce.payroll_prepare'));
    Route::get('{id}', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'show'])->middleware($perm('workforce.payroll_prepare'));
    Route::delete('{id}', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'destroy'])->middleware($perm('workforce.payroll_prepare'));

    // Payroll Run Actions
    Route::post('{id}/compute', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'compute'])->middleware($perm('workforce.payroll_prepare'));
    Route::post('{id}/compute-calculations', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'computeCalculations'])->middleware($perm('workforce.payroll_prepare'));
    Route::post('{id}/validate', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'validate'])->middleware($perm('workforce.payroll_validate'));
    Route::post('{id}/export', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'export'])->middleware($perm('workforce.payroll_export'));
    Route::post('{id}/recompute', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'recompute'])->middleware($perm('workforce.payroll_prepare'));

    // Payroll Run Data
    Route::get('{id}/lines', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'lines'])->middleware($perm('workforce.payroll_prepare'));
    Route::get('{id}/lines/{lineId}', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'showLine'])->middleware($perm('workforce.payroll_prepare'));
    Route::get('{id}/calculations', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'calculations'])->middleware($perm('workforce.payroll_prepare'));
    Route::get('{id}/documents', [\App\Modules\Workforce\Payroll\Http\PayrollRunController::class, 'documents'])->middleware($perm('workforce.payroll_prepare'));
});

// ── Workforce DSN Declarations ────────────────────────────────
Route::prefix('workforce/dsn')->middleware('company.access:use-module,workforce_payroll')->group(function () {
    // ANO-014: DSN is a social declaration to authorities — gate on payroll_export (its nav gate).
    $perm = fn (string $key) => 'company.access:use-permission,'.$key;
    Route::get('statistics', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'statistics'])->middleware($perm('workforce.payroll_export'));
    Route::get('/', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'index'])->middleware($perm('workforce.payroll_export'));
    Route::post('export', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'export'])->middleware($perm('workforce.payroll_export'));
    Route::get('{id}', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'show'])->middleware($perm('workforce.payroll_export'));
    Route::post('{id}/submit', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'submit'])->middleware($perm('workforce.payroll_export'));
    Route::post('{id}/poll', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'poll'])->middleware($perm('workforce.payroll_export'));
    Route::get('{id}/validation', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'validationErrors'])->middleware($perm('workforce.payroll_export'));
    Route::get('{id}/history', [\App\Modules\Workforce\Payroll\Http\DsnDeclarationController::class, 'history'])->middleware($perm('workforce.payroll_export'));
});

// ── Workforce Documents ───────────────────────────────────────
Route::prefix('workforce/documents')->middleware('company.access:use-module,workforce_documents')->group(function () {
    // ANO-014: view to read, generate to produce documents/payslips.
    $perm = fn (string $key) => 'company.access:use-permission,'.$key;
    Route::get('statistics', [\App\Modules\Workforce\Documents\Http\WorkforceDocumentController::class, 'statistics'])->middleware($perm('workforce_documents.view'));
    Route::get('/', [\App\Modules\Workforce\Documents\Http\WorkforceDocumentController::class, 'index'])->middleware($perm('workforce_documents.view'));
    Route::post('generate', [\App\Modules\Workforce\Documents\Http\WorkforceDocumentController::class, 'generate'])->middleware($perm('workforce_documents.generate'));
    Route::post('payslip-drafts', [\App\Modules\Workforce\Documents\Http\WorkforceDocumentController::class, 'generatePayslipDrafts'])->middleware($perm('workforce_documents.generate'));
    Route::post('payslip-official', [\App\Modules\Workforce\Documents\Http\WorkforceDocumentController::class, 'generateOfficialPayslips'])->middleware($perm('workforce_documents.generate'));
    Route::get('payroll-run/{runId}', [\App\Modules\Workforce\Documents\Http\WorkforceDocumentController::class, 'forPayrollRun'])->middleware($perm('workforce_documents.view'));
    Route::get('{id}', [\App\Modules\Workforce\Documents\Http\WorkforceDocumentController::class, 'show'])->middleware($perm('workforce_documents.view'));
});

// ── Documentation (ADR-356: routes moved to /api/help-center/* unified API) ──
