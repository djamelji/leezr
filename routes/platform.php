<?php

use App\Modules\Platform\Companies\Http\CompanyBillingAdminController;
use App\Modules\Platform\Companies\Http\CompanyController;
use App\Modules\Platform\Companies\Http\CompanyModuleController;
use App\Modules\Platform\Companies\Http\CompanyProfileAdminController;
use App\Modules\Platform\Companies\Http\CompanySubscriptionAdminController;
use App\Modules\Platform\Companies\Http\CompanyUserController;
use App\Modules\Platform\Billing\Http\BillingConfigController;
use App\Modules\Platform\Billing\Http\PlatformBillingController;
use App\Modules\Platform\Billing\Http\PlatformInvoiceMutationController;
use App\Modules\Platform\Billing\Http\PlatformAdvancedMutationController;
use App\Modules\Platform\Billing\Http\PaymentModuleController;
use App\Modules\Platform\Billing\Http\PaymentMethodRuleController;
use App\Modules\Platform\Billing\Http\PlatformBillingPolicyController;
use App\Modules\Platform\Billing\Http\PlatformFinancialController;
use App\Modules\Platform\Billing\Http\PlatformBillingMetricsController;
use App\Modules\Platform\Billing\Http\PlatformBillingWidgetsController;
use App\Modules\Platform\Billing\Http\BillingMetricsExportController;
use App\Modules\Platform\Billing\Http\BillingExportController;
use App\Modules\Platform\Billing\Http\BillingBulkActionController;
use App\Modules\Platform\Billing\Http\CouponCrudController;
use App\Modules\Platform\Notifications\Http\PlatformNotificationController;
use App\Modules\Platform\Billing\Http\AuditExportController;
use App\Modules\Platform\Billing\Http\PlatformRecoveryController;
use App\Modules\Platform\Dashboard\Http\DashboardWidgetController;
use App\Modules\Platform\Dashboard\Http\DashboardLayoutController;
use App\Modules\Platform\Dashboard\Http\DashboardCockpitController;
use App\Modules\Platform\AI\Http\PlatformAiController;
use App\Modules\Platform\AI\Http\PlatformAiMutationController;
use App\Modules\Platform\Plans\Http\PlanCrudController;
use App\Modules\Platform\Fields\Http\FieldActivationController;
use App\Modules\Platform\Fields\Http\FieldDefinitionController;
use App\Modules\Platform\Jobdomains\Http\JobdomainController;
use App\Modules\Platform\Jobdomains\Http\JobdomainOverlayController;
use App\Modules\Platform\Modules\Http\ModuleController;
use App\Modules\Platform\Maintenance\Http\MaintenanceSettingsController;
use App\Modules\Platform\Settings\Http\GeneralSettingsController;
use App\Modules\Platform\Settings\Http\SessionSettingsController;
use App\Modules\Platform\Settings\Http\TypographyController;
use App\Modules\Platform\Settings\Http\ThemeController;
use App\Modules\Platform\Roles\Http\PermissionController;
use App\Modules\Platform\Roles\Http\RoleController;
use App\Modules\Platform\Users\Http\UserController;
use App\Modules\Platform\Markets\Http\MarketCrudController;
use App\Modules\Platform\Markets\Http\LegalStatusController;
use App\Modules\Platform\Markets\Http\LanguageController;
use App\Modules\Platform\Markets\Http\FxRateController;
use App\Modules\Platform\Translations\Http\TranslationController;
use App\Modules\Platform\Translations\Http\TranslationMatrixController;
use App\Modules\Platform\Translations\Http\OverrideController;
use App\Modules\Platform\Settings\Http\WorldSettingsController;
use App\Modules\Platform\Documents\Http\DocumentTypeCatalogController;
use App\Modules\Platform\Realtime\Http\RealtimeGovernanceController;
use App\Modules\Platform\Notifications\Http\PlatformNotificationTopicController;
use App\Modules\Platform\Audit\Http\PlatformAuditLogController;
use App\Modules\Platform\Security\Http\SecurityAlertController;
use App\Modules\Infrastructure\Navigation\Http\NavController;
use App\Modules\Infrastructure\Theme\Http\PlatformThemePreferenceController;
use App\Modules\Infrastructure\AdminAuth\Http\PlatformAccountController;
use App\Modules\Infrastructure\AdminAuth\Http\PlatformAuthController;
use App\Modules\Infrastructure\AdminAuth\Http\PlatformPasswordResetController;
use App\Modules\Infrastructure\AdminAuth\Http\PlatformTwoFactorController;
use App\Modules\Platform\Support\Http\PlatformSupportTicketController;
use App\Modules\Platform\Support\Http\PlatformSupportMessageController;
use App\Modules\Platform\Automations\Http\AutomationController;
use App\Modules\Platform\Alerts\Http\PlatformAlertController;
use App\Modules\Infrastructure\Realtime\Http\RealtimeStreamController;
use Illuminate\Support\Facades\Route;

// Metrics export — bearer token auth, no session guard (ADR-311)
Route::get('/billing/metrics-export', BillingMetricsExportController::class)
    ->middleware('module.active:platform.billing');

// Platform auth — public (throttled)
Route::post('/login', [PlatformAuthController::class, 'login'])
    ->middleware('throttle:15,1');
Route::post('/2fa/verify', [PlatformAuthController::class, 'verify2fa'])
    ->middleware('throttle:5,1');
Route::post('/forgot-password', [PlatformPasswordResetController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/reset-password', [PlatformPasswordResetController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

// Authenticated platform routes
Route::middleware(['auth:platform', 'session.governance'])->group(function () {
    // Routes exempt from 2FA requirement (needed for setup + basic auth)
    Route::get('/me', [PlatformAuthController::class, 'me']);
    Route::get('/nav', [NavController::class, 'platform']);
    Route::post('/logout', [PlatformAuthController::class, 'logout']);

    // My Account (ADR-350) — exempt from 2FA so admins can set it up
    Route::put('/me/profile', [PlatformAccountController::class, 'updateProfile']);
    Route::put('/me/password', [PlatformAccountController::class, 'updatePassword']);
    Route::get('/me/notification-preferences', [PlatformAccountController::class, 'notificationPreferences']);
    Route::put('/me/notification-preferences', [PlatformAccountController::class, 'updateNotificationPreferences']);

    // My notifications inbox (in-app notifications for the logged-in admin)
    Route::get('/me/notifications', [PlatformNotificationController::class, 'index']);
    Route::get('/me/notifications/unread-count', [PlatformNotificationController::class, 'unreadCount']);
    Route::post('/me/notifications/{id}/read', [PlatformNotificationController::class, 'markRead']);
    Route::post('/me/notifications/read-all', [PlatformNotificationController::class, 'markAllRead']);
    Route::delete('/me/notifications/{id}', [PlatformNotificationController::class, 'destroy']);

    // ADR-431: SSE realtime stream for platform scope (exempt from 2FA — long-lived connection)
    Route::get('/realtime/stream', RealtimeStreamController::class);

    // 2FA management (ADR-351) — exempt from 2FA (needed to enable it)
    Route::post('/2fa/enable', [PlatformTwoFactorController::class, 'enable']);
    Route::post('/2fa/confirm', [PlatformTwoFactorController::class, 'confirm']);
    Route::delete('/2fa', [PlatformTwoFactorController::class, 'disable']);
    Route::post('/2fa/backup-codes', [PlatformTwoFactorController::class, 'regenerateBackupCodes']);
    Route::get('/2fa/status', [PlatformTwoFactorController::class, 'status']);

    // Theme preference (ADR-159, no module gate — platform admins always have it)
    Route::put('/theme-preference', [PlatformThemePreferenceController::class, 'update']);

    // All routes below require 2FA to be enabled (ADR-351)
    Route::middleware(['platform.2fa'])->group(function () {

    // Companies
    Route::middleware(['module.active:platform.companies', 'platform.permission:manage_companies'])->group(function () {
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::get('/companies/{id}', [CompanyController::class, 'show']);
        Route::put('/companies/{id}', [CompanyProfileAdminController::class, 'update']);
        Route::get('/companies/{id}/billing', [CompanyController::class, 'billing']);
        Route::get('/companies/{id}/members', [CompanyController::class, 'members']);
        Route::get('/companies/{id}/activity', [CompanyController::class, 'activity']);
        Route::put('/companies/{id}/suspend', [CompanyController::class, 'suspend']);
        Route::put('/companies/{id}/reactivate', [CompanyController::class, 'reactivate']);
        Route::get('/companies/{id}/plan-preview', [CompanyBillingAdminController::class, 'planChangePreview']);
        Route::put('/companies/{id}/plan', [CompanyBillingAdminController::class, 'updatePlan']);
        Route::post('/companies/{id}/wallet', [CompanyBillingAdminController::class, 'adjustWallet']);
        Route::get('/companies/{id}/wallet-history', [CompanyBillingAdminController::class, 'walletHistory']);
        Route::get('/companies/{id}/payment-methods', [CompanySubscriptionAdminController::class, 'paymentMethods']);
        Route::put('/companies/{id}/payment-methods/{pmId}/default', [CompanySubscriptionAdminController::class, 'setDefaultPaymentMethod']);
        Route::delete('/companies/{id}/payment-methods/{pmId}', [CompanySubscriptionAdminController::class, 'deletePaymentMethod']);
        Route::get('/companies/{id}/subscription/cancel-preview', [CompanySubscriptionAdminController::class, 'cancelPreview']);
        Route::put('/companies/{id}/subscription/cancel', [CompanySubscriptionAdminController::class, 'cancelSubscription']);
        Route::put('/companies/{id}/subscription/undo-cancel', [CompanySubscriptionAdminController::class, 'undoCancelSubscription']);
        Route::put('/companies/{id}/subscription/extend-trial', [CompanySubscriptionAdminController::class, 'extendTrial']);
        Route::post('/companies/{id}/payment-methods/setup-intent', [CompanySubscriptionAdminController::class, 'createSetupIntent']);
        Route::post('/companies/{id}/payment-methods/confirm', [CompanySubscriptionAdminController::class, 'confirmPaymentMethod']);
        Route::put('/companies/{id}/modules/{key}/enable', [CompanyModuleController::class, 'enable']);
        Route::put('/companies/{id}/modules/{key}/disable', [CompanyModuleController::class, 'disable']);
    });

    // Plans
    Route::middleware(['module.active:platform.plans', 'platform.permission:manage_plans'])->group(function () {
        Route::get('/plans', [PlanCrudController::class, 'index']);
        Route::get('/plans/{key}', [PlanCrudController::class, 'show']);
        Route::post('/plans', [PlanCrudController::class, 'store']);
        Route::put('/plans/{id}', [PlanCrudController::class, 'update']);
        Route::put('/plans/{id}/toggle-active', [PlanCrudController::class, 'toggleActive']);
    });

    // Company users (read-only supervision)
    Route::middleware(['module.active:platform.companies', 'platform.permission:view_company_users'])->group(function () {
        Route::get('/company-users', [CompanyUserController::class, 'index']);
    });

    // Platform users (CRUD)
    Route::middleware(['module.active:platform.users', 'platform.permission:manage_platform_users'])->group(function () {
        Route::get('/platform-users', [UserController::class, 'index']);
        Route::post('/platform-users', [UserController::class, 'store']);
        Route::put('/platform-users/{id}', [UserController::class, 'update']);
        Route::delete('/platform-users/{id}', [UserController::class, 'destroy']);
    });

    // Platform user credentials (privileged)
    Route::middleware(['module.active:platform.users', 'platform.permission:manage_platform_user_credentials'])->group(function () {
        Route::post('/platform-users/{id}/reset-password', [PlatformPasswordResetController::class, 'adminResetPassword']);
        Route::put('/platform-users/{id}/password', [UserController::class, 'setPassword']);
    });

    // Roles (CRUD)
    Route::middleware(['module.active:platform.roles', 'platform.permission:manage_roles'])->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{id}', [RoleController::class, 'update']);
        Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
    });

    // Permissions (read-only)
    Route::middleware(['module.active:platform.roles', 'platform.permission:manage_roles'])->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
    });

    // Modules
    Route::middleware(['module.active:platform.modules', 'platform.permission:manage_modules'])->group(function () {
        Route::get('/modules', [ModuleController::class, 'index']);
        Route::post('/modules/sync', [ModuleController::class, 'sync']);
        Route::get('/modules/{key}', [ModuleController::class, 'show']);
        Route::put('/modules/{key}/toggle', [ModuleController::class, 'toggle']);
        Route::put('/modules/{key}/config', [ModuleController::class, 'updateConfig']);
    });

    // Field Definitions + Activations
    Route::middleware(['module.active:platform.fields', 'platform.permission:manage_field_definitions'])->group(function () {
        Route::get('/field-definitions', [FieldDefinitionController::class, 'index']);
        Route::post('/field-definitions', [FieldDefinitionController::class, 'store']);
        Route::put('/field-definitions/{id}', [FieldDefinitionController::class, 'update']);
        Route::delete('/field-definitions/{id}', [FieldDefinitionController::class, 'destroy']);

        Route::get('/field-activations', [FieldActivationController::class, 'index']);
        Route::post('/field-activations', [FieldActivationController::class, 'upsert']);
    });

    // Platform user profile (show with dynamic fields)
    Route::middleware(['module.active:platform.users', 'platform.permission:manage_platform_users'])->group(function () {
        Route::get('/platform-users/{id}', [UserController::class, 'show']);
    });

    // General Settings
    Route::middleware(['module.active:platform.settings', 'platform.permission:manage_theme_settings'])->group(function () {
        Route::get('/general-settings', [GeneralSettingsController::class, 'show']);
        Route::put('/general-settings', [GeneralSettingsController::class, 'update']);
    });

    // Theme Settings
    Route::middleware(['module.active:platform.settings', 'platform.permission:manage_theme_settings'])->group(function () {
        Route::get('/theme', [ThemeController::class, 'show']);
        Route::put('/theme', [ThemeController::class, 'update']);
    });

    // Typography Settings
    Route::middleware(['module.active:platform.settings', 'platform.permission:manage_theme_settings'])->group(function () {
        Route::get('/typography', [TypographyController::class, 'show']);
        Route::put('/typography', [TypographyController::class, 'update']);
        Route::post('/font-families', [TypographyController::class, 'createFamily']);
        Route::post('/font-families/{familyId}/fonts', [TypographyController::class, 'uploadFont']);
        Route::delete('/font-families/{familyId}/fonts/{fontId}', [TypographyController::class, 'deleteFont']);
        Route::delete('/font-families/{familyId}', [TypographyController::class, 'deleteFamily']);
    });

    // Session Settings
    Route::middleware(['module.active:platform.settings', 'platform.permission:manage_session_settings'])->group(function () {
        Route::get('/session-settings', [SessionSettingsController::class, 'show']);
        Route::put('/session-settings', [SessionSettingsController::class, 'update']);
    });

    // World Settings (localization, currency, timezone)
    Route::middleware(['module.active:platform.settings', 'platform.permission:manage_theme_settings'])->group(function () {
        Route::get('/world-settings', [WorldSettingsController::class, 'show']);
        Route::put('/world-settings', [WorldSettingsController::class, 'update']);
    });

    // Maintenance Settings
    Route::middleware(['module.active:platform.settings', 'platform.permission:manage_maintenance'])->group(function () {
        Route::get('/maintenance-settings', [MaintenanceSettingsController::class, 'show']);
        Route::put('/maintenance-settings', [MaintenanceSettingsController::class, 'update']);
        Route::get('/maintenance/my-ip', [MaintenanceSettingsController::class, 'myIp']);
    });

    // Document Type Catalog (ADR-182 → platform.documents module)
    Route::middleware(['module.active:platform.documents', 'platform.permission:manage_document_catalog'])->group(function () {
        Route::get('/documents', [DocumentTypeCatalogController::class, 'index']);
        Route::get('/documents/{id}', [DocumentTypeCatalogController::class, 'show']);
        Route::post('/documents', [DocumentTypeCatalogController::class, 'store']);
        Route::put('/documents/{id}', [DocumentTypeCatalogController::class, 'update']);
        Route::put('/documents/{id}/archive', [DocumentTypeCatalogController::class, 'archive']);
        Route::put('/documents/{id}/restore', [DocumentTypeCatalogController::class, 'restore']);
        Route::post('/documents/sync', [DocumentTypeCatalogController::class, 'sync']);
    });

    // Billing read endpoints (ADR-135 LOT4)
    Route::middleware(['module.active:platform.billing', 'platform.permission:view_billing'])->group(function () {
        Route::get('/billing/invoices', [PlatformBillingController::class, 'invoices']);
        Route::get('/billing/invoices/export', BillingExportController::class);
        Route::get('/billing/invoices/{id}', [PlatformBillingController::class, 'invoiceDetail']);
        Route::get('/billing/invoices/{id}/pdf', [PlatformBillingController::class, 'invoicePdf']);
        Route::get('/billing/payments', [PlatformBillingController::class, 'payments']);
        Route::get('/billing/credit-notes', [PlatformBillingController::class, 'creditNotes']);
        Route::get('/billing/wallets', [PlatformBillingController::class, 'wallets']);
        Route::get('/billing/all-subscriptions', [PlatformBillingController::class, 'subscriptions']);
        Route::get('/billing/dunning', [PlatformBillingController::class, 'dunning']);

        // Financial governance read (ADR-144 D4b)
        Route::get('/billing/ledger/trial-balance', [PlatformFinancialController::class, 'trialBalance']);
        Route::get('/billing/ledger/entries', [PlatformFinancialController::class, 'ledgerEntries']);
        Route::get('/billing/financial-periods', [PlatformFinancialController::class, 'financialPeriods']);
        Route::get('/billing/forensics/timeline', [PlatformFinancialController::class, 'forensicsTimeline']);
        Route::get('/billing/forensics/snapshots', [PlatformFinancialController::class, 'forensicsSnapshots']);
        Route::get('/billing/drift-history', [PlatformFinancialController::class, 'driftHistory']);
        Route::get('/billing/companies/{id}/financial-freeze', [PlatformFinancialController::class, 'freezeState']);

        // Billing widgets (ADR-147 D4e)
        Route::get('/billing/widgets', [PlatformBillingWidgetsController::class, 'index']);
        Route::get('/billing/widgets/{key}', [PlatformBillingWidgetsController::class, 'show']);

        // Billing metrics — MRR/ARR dashboard (ADR-227)
        Route::get('/billing/metrics', PlatformBillingMetricsController::class);

        // Recovery status — ADR-236
        Route::get('/billing/recovery-status', [PlatformBillingController::class, 'recoveryStatus']);

        // Scheduled debits (ADR-328 S8)
        Route::get('/billing/scheduled-debits', [PlatformBillingController::class, 'scheduledDebits']);
    });

    // Dashboard engine (ADR-149 D4e.3)
    Route::middleware(['module.active:platform.dashboard'])->group(function () {
        Route::get('/dashboard/widgets/catalog', [DashboardWidgetController::class, 'catalog']);
        Route::post('/dashboard/widgets/data', [DashboardWidgetController::class, 'batchResolve']);
        Route::get('/dashboard/layout', [DashboardLayoutController::class, 'show']);
        Route::put('/dashboard/layout', [DashboardLayoutController::class, 'update']);
        Route::get('/dashboard/layout/presets', [DashboardLayoutController::class, 'presets']);

        // Cockpit — decision-oriented landing (ADR-441)
        Route::get('/dashboard/attention', [DashboardCockpitController::class, 'attention']);
        Route::get('/dashboard/health', [DashboardCockpitController::class, 'health']);
    });

    // Billing / Payments governance (ADR-101, ADR-102)
    Route::middleware(['module.active:platform.billing', 'platform.permission:manage_billing'])->group(function () {
        Route::get('/billing/providers', [BillingConfigController::class, 'providers']);
        Route::get('/billing/config', [BillingConfigController::class, 'showConfig']);
        Route::put('/billing/config', [BillingConfigController::class, 'updateConfig']);
        Route::get('/billing/policies', [BillingConfigController::class, 'policies']);
        Route::put('/billing/policies', [BillingConfigController::class, 'updatePolicies']);

        // Billing engine policy singleton (ADR-135 D0)
        Route::get('/billing/billing-policy', [PlatformBillingPolicyController::class, 'show']);
        Route::put('/billing/billing-policy', [PlatformBillingPolicyController::class, 'update']);

        // Invoice mutations (ADR-135 D2a)
        Route::put('/billing/invoices/{invoice}/mark-paid-offline', [PlatformInvoiceMutationController::class, 'markPaidOffline']);
        Route::put('/billing/invoices/{invoice}/void', [PlatformInvoiceMutationController::class, 'void']);
        Route::put('/billing/invoices/{invoice}/notes', [PlatformInvoiceMutationController::class, 'updateNotes']);

        // Advanced invoice mutations (ADR-136 D2c)
        Route::post('/billing/invoices/{invoice}/refund', [PlatformAdvancedMutationController::class, 'refund']);
        Route::post('/billing/invoices/{invoice}/retry-payment', [PlatformAdvancedMutationController::class, 'retryPayment']);
        Route::put('/billing/invoices/{invoice}/dunning-transition', [PlatformAdvancedMutationController::class, 'forceDunningTransition']);
        Route::post('/billing/invoices/{invoice}/credit-note', [PlatformAdvancedMutationController::class, 'issueCreditNote']);
        Route::put('/billing/invoices/{invoice}/write-off', [PlatformAdvancedMutationController::class, 'writeOff']);

        // Bulk actions (ADR-315)
        Route::post('/billing/invoices/bulk-void', [BillingBulkActionController::class, 'bulkVoid']);
        Route::post('/billing/invoices/bulk-retry', [BillingBulkActionController::class, 'bulkRetry']);

        // Coupons CRUD (ADR-316)
        Route::get('/billing/coupons', [CouponCrudController::class, 'index']);
        Route::post('/billing/coupons', [CouponCrudController::class, 'store']);
        Route::put('/billing/coupons/{id}', [CouponCrudController::class, 'update']);
        Route::delete('/billing/coupons/{id}', [CouponCrudController::class, 'destroy']);

        Route::get('/billing/subscriptions', [BillingConfigController::class, 'subscriptions']);
        Route::put('/billing/subscriptions/{id}/approve', [BillingConfigController::class, 'approveSubscription']);
        Route::put('/billing/subscriptions/{id}/reject', [BillingConfigController::class, 'rejectSubscription']);

        // Payment modules governance (ADR-124)
        Route::get('/billing/payment-modules', [PaymentModuleController::class, 'index']);
        Route::put('/billing/payment-modules/{providerKey}/install', [PaymentModuleController::class, 'install']);
        Route::put('/billing/payment-modules/{providerKey}/activate', [PaymentModuleController::class, 'activate']);
        Route::put('/billing/payment-modules/{providerKey}/deactivate', [PaymentModuleController::class, 'deactivate']);
        Route::put('/billing/payment-modules/{providerKey}/credentials', [PaymentModuleController::class, 'updateCredentials']);
        Route::get('/billing/payment-modules/{providerKey}/health', [PaymentModuleController::class, 'health']);

        // Payment method rules governance (ADR-124)
        Route::get('/billing/payment-rules', [PaymentMethodRuleController::class, 'index']);
        Route::post('/billing/payment-rules', [PaymentMethodRuleController::class, 'store']);
        Route::put('/billing/payment-rules/{id}', [PaymentMethodRuleController::class, 'update']);
        Route::delete('/billing/payment-rules/{id}', [PaymentMethodRuleController::class, 'destroy']);
        Route::get('/billing/payment-rules/preview', [PaymentMethodRuleController::class, 'preview']);

        // Financial governance write (ADR-144 D4b)
        Route::post('/billing/financial-periods/close', [PlatformFinancialController::class, 'closePeriod']);
        Route::put('/billing/companies/{id}/financial-freeze', [PlatformFinancialController::class, 'toggleFreeze']);
        Route::post('/billing/reconcile', [PlatformFinancialController::class, 'reconcile']);

        // Audit export (ADR-311)
        Route::get('/billing/audit-export', AuditExportController::class);

        // Recovery operations (ADR-345)
        Route::post('/billing/recover-checkouts', [PlatformRecoveryController::class, 'recoverCheckouts']);
        Route::post('/billing/recover-webhooks', [PlatformRecoveryController::class, 'recoverWebhooks']);
        Route::post('/billing/replay-dead-letters', [PlatformRecoveryController::class, 'replayAllDeadLetters']);
        Route::post('/billing/replay-dead-letters/{id}', [PlatformRecoveryController::class, 'replayDeadLetter']);
        Route::get('/billing/dead-letters', [PlatformRecoveryController::class, 'listDeadLetters']);
    });

    // AI Gateway governance (ADR-411)
    Route::middleware(['module.active:platform.ai', 'platform.permission:view_ai'])->group(function () {
        Route::get('/ai/providers', [PlatformAiController::class, 'providers']);
        Route::get('/ai/usage', [PlatformAiController::class, 'usage']);
        Route::get('/ai/routing', [PlatformAiController::class, 'routing']);
        Route::get('/ai/config', [PlatformAiController::class, 'config']);
        Route::get('/ai/health', [PlatformAiController::class, 'health']);
    });

    Route::middleware(['module.active:platform.ai', 'platform.permission:manage_ai'])->group(function () {
        Route::put('/ai/config', [PlatformAiMutationController::class, 'updateConfig']);
        Route::put('/ai/providers/{providerKey}/install', [PlatformAiMutationController::class, 'installProvider']);
        Route::put('/ai/providers/{providerKey}/activate', [PlatformAiMutationController::class, 'activateProvider']);
        Route::put('/ai/providers/{providerKey}/deactivate', [PlatformAiMutationController::class, 'deactivateProvider']);
        Route::put('/ai/providers/{providerKey}/credentials', [PlatformAiMutationController::class, 'updateProviderCredentials']);
        Route::put('/ai/routing', [PlatformAiMutationController::class, 'updateRouting']);
        Route::post('/ai/providers/{providerKey}/health-check', [PlatformAiMutationController::class, 'healthCheck']);
    });

    // Markets governance (ADR-104)
    Route::middleware(['module.active:platform.markets', 'platform.permission:manage_markets'])->group(function () {
        // Markets CRUD
        Route::get('/markets/export', [MarketCrudController::class, 'export']);
        Route::post('/markets/import-preview', [MarketCrudController::class, 'importPreview']);
        Route::post('/markets/import-apply', [MarketCrudController::class, 'importApply']);
        Route::get('/markets', [MarketCrudController::class, 'index']);
        Route::get('/markets/{key}', [MarketCrudController::class, 'show']);
        Route::post('/markets', [MarketCrudController::class, 'store']);
        Route::put('/markets/{id}', [MarketCrudController::class, 'update']);
        Route::put('/markets/{id}/toggle-active', [MarketCrudController::class, 'toggleActive']);
        Route::put('/markets/{id}/set-default', [MarketCrudController::class, 'setDefault']);

        // Legal statuses (nested under market)
        Route::post('/markets/{marketKey}/legal-statuses', [LegalStatusController::class, 'store']);
        Route::put('/legal-statuses/{id}', [LegalStatusController::class, 'update']);
        Route::delete('/legal-statuses/{id}', [LegalStatusController::class, 'destroy']);
        Route::put('/markets/{marketKey}/legal-statuses/reorder', [LegalStatusController::class, 'reorder']);

        // Languages
        Route::get('/languages/export', [LanguageController::class, 'export']);
        Route::post('/languages/import-apply', [LanguageController::class, 'importApply']);
        Route::get('/languages', [LanguageController::class, 'index']);
        Route::post('/languages', [LanguageController::class, 'store']);
        Route::put('/languages/{id}', [LanguageController::class, 'update']);
        Route::delete('/languages/{id}', [LanguageController::class, 'destroy']);
        Route::put('/languages/{id}/toggle-active', [LanguageController::class, 'toggleActive']);

        // FX rates
        Route::get('/fx-rates', [FxRateController::class, 'index']);
        Route::post('/fx-rates/refresh', [FxRateController::class, 'refresh']);
    });

    // Translation governance (ADR-104 — separate permission)
    Route::middleware(['module.active:platform.translations', 'platform.permission:manage_translations'])->group(function () {
        // Stats + Namespaces
        Route::get('/translations/stats', [TranslationMatrixController::class, 'stats']);
        Route::get('/translations/namespaces', [TranslationMatrixController::class, 'namespaces']);

        // Matrix editor (primary editing interface)
        Route::get('/translations/matrix', [TranslationMatrixController::class, 'index']);
        Route::put('/translations/matrix', [TranslationMatrixController::class, 'update']);

        // Bundles CRUD
        Route::get('/translations', [TranslationController::class, 'index']);
        Route::put('/translations/{id}', [TranslationController::class, 'update']);
        Route::post('/translations/import-preview', [TranslationController::class, 'importPreview']);
        Route::post('/translations/import-apply', [TranslationController::class, 'importApply']);
        Route::get('/translations/export/{locale}', [TranslationController::class, 'export']);
        Route::get('/translations/{locale}/{namespace}', [TranslationController::class, 'show']);

        // Market overrides
        Route::get('/translations/overrides/{marketKey}/{locale}', [OverrideController::class, 'index']);
        Route::put('/translations/overrides/{marketKey}', [OverrideController::class, 'upsert']);
        Route::delete('/translations/overrides/{id}', [OverrideController::class, 'destroy']);
    });

    // Audit logs (ADR-130)
    Route::middleware(['module.active:platform.audit', 'platform.permission:view_audit_logs'])->group(function () {
        Route::get('/audit/platform', [PlatformAuditLogController::class, 'platformLogs']);
        Route::get('/audit/companies', [PlatformAuditLogController::class, 'companyLogs']);
        Route::get('/audit/actions', [PlatformAuditLogController::class, 'actions']);
    });

    // Activity Feed (ADR-440)
    Route::middleware(['module.active:platform.activity', 'platform.permission:view_audit_logs'])->group(function () {
        Route::get('/activity', [\App\Modules\Platform\Activity\Http\PlatformActivityController::class, 'index']);
        Route::get('/activity/types', [\App\Modules\Platform\Activity\Http\PlatformActivityController::class, 'types']);
    });

    // Security Alerts (ADR-129, ADR-157)
    Route::middleware(['module.active:platform.security'])->group(function () {
        Route::middleware(['platform.permission:security.alerts.view'])->group(function () {
            Route::get('/security/alerts', [SecurityAlertController::class, 'index']);
            Route::get('/security/alert-types', [SecurityAlertController::class, 'alertTypes']);
        });

        Route::middleware(['platform.permission:security.alerts.manage'])->group(function () {
            Route::put('/security/alerts/{id}/acknowledge', [SecurityAlertController::class, 'acknowledge']);
            Route::put('/security/alerts/{id}/resolve', [SecurityAlertController::class, 'resolve']);
            Route::put('/security/alerts/{id}/false-positive', [SecurityAlertController::class, 'falsePositive']);
        });
    });

    // Realtime Governance (ADR-127, ADR-157)
    Route::middleware(['module.active:platform.realtime'])->group(function () {
        Route::middleware(['platform.permission:realtime.metrics.view'])->group(function () {
            Route::get('/realtime/status', [RealtimeGovernanceController::class, 'status']);
            Route::get('/realtime/metrics', [RealtimeGovernanceController::class, 'metrics']);
        });

        Route::middleware(['platform.permission:realtime.connections.view'])->group(function () {
            Route::get('/realtime/connections', [RealtimeGovernanceController::class, 'connections']);
        });

        Route::middleware(['platform.permission:realtime.governance'])->group(function () {
            Route::post('/realtime/flush', [RealtimeGovernanceController::class, 'flush']);
            Route::post('/realtime/kill-switch', [RealtimeGovernanceController::class, 'killSwitch']);
        });
    });

    // Notification topic governance
    Route::middleware(['module.active:platform.notifications', 'platform.permission:manage_notifications'])->group(function () {
        Route::get('/notifications/topics', [PlatformNotificationTopicController::class, 'index']);
        Route::put('/notifications/topics/{key}', [PlatformNotificationTopicController::class, 'update']);
        Route::put('/notifications/topics/{key}/toggle', [PlatformNotificationTopicController::class, 'toggle']);
    });

    // Heartbeat (session keepalive — governance middleware handles TTL header)
    Route::post('/heartbeat', fn () => response()->noContent());

    // Job Domains (CRUD)
    Route::middleware(['module.active:platform.jobdomains', 'platform.permission:manage_jobdomains'])->group(function () {
        Route::get('/jobdomains', [JobdomainController::class, 'index']);
        Route::get('/jobdomains/{id}', [JobdomainController::class, 'show']);
        Route::post('/jobdomains', [JobdomainController::class, 'store']);
        Route::put('/jobdomains/{id}', [JobdomainController::class, 'update']);
        Route::delete('/jobdomains/{id}', [JobdomainController::class, 'destroy']);

        // Market Overlays
        Route::get('/jobdomains/{jobdomainKey}/overlays', [JobdomainOverlayController::class, 'index']);
        Route::put('/jobdomains/{jobdomainKey}/overlays/{marketKey}', [JobdomainOverlayController::class, 'upsert']);
        Route::delete('/jobdomains/{jobdomainKey}/overlays/{marketKey}', [JobdomainOverlayController::class, 'destroy']);
    });

    // ── Support Tickets ─────────────────────────────────────────
    Route::middleware(['module.active:platform.support', 'platform.permission:manage_support'])->group(function () {
        Route::get('/support/metrics', [PlatformSupportTicketController::class, 'metrics']);
        Route::get('/support/tickets', [PlatformSupportTicketController::class, 'index']);
        Route::get('/support/tickets/{id}', [PlatformSupportTicketController::class, 'show']);
        Route::put('/support/tickets/{id}/assign', [PlatformSupportTicketController::class, 'assign']);
        Route::put('/support/tickets/{id}/resolve', [PlatformSupportTicketController::class, 'resolve']);
        Route::put('/support/tickets/{id}/close', [PlatformSupportTicketController::class, 'close']);
        Route::put('/support/tickets/{id}/priority', [PlatformSupportTicketController::class, 'updatePriority']);
        Route::get('/support/tickets/{id}/messages', [PlatformSupportMessageController::class, 'index']);
        Route::post('/support/tickets/{id}/messages', [PlatformSupportMessageController::class, 'store']);
        Route::post('/support/tickets/{id}/internal-notes', [PlatformSupportMessageController::class, 'storeInternal']);
    });

    // ── Documentation ───────────────────────────────────────────
    Route::middleware(['module.active:platform.documentation', 'platform.permission:manage_documentation'])->group(function () {
        Route::get('/documentation/topics', [\App\Modules\Platform\Documentation\Http\PlatformDocTopicController::class, 'index']);
        Route::post('/documentation/topics', [\App\Modules\Platform\Documentation\Http\PlatformDocTopicController::class, 'store']);
        Route::get('/documentation/topics/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocTopicController::class, 'show']);
        Route::put('/documentation/topics/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocTopicController::class, 'update']);
        Route::delete('/documentation/topics/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocTopicController::class, 'destroy']);
        Route::get('/documentation/articles', [\App\Modules\Platform\Documentation\Http\PlatformDocArticleController::class, 'index']);
        Route::post('/documentation/articles', [\App\Modules\Platform\Documentation\Http\PlatformDocArticleController::class, 'store']);
        Route::get('/documentation/articles/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocArticleController::class, 'show']);
        Route::put('/documentation/articles/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocArticleController::class, 'update']);
        Route::delete('/documentation/articles/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocArticleController::class, 'destroy']);
        Route::get('/documentation/feedback-stats', [\App\Modules\Platform\Documentation\Http\PlatformDocArticleController::class, 'feedbackStats']);
        Route::get('/documentation/search-misses', [\App\Modules\Platform\Documentation\Http\PlatformDocArticleController::class, 'searchMisses']);
        Route::get('/documentation/groups', [\App\Modules\Platform\Documentation\Http\PlatformDocGroupController::class, 'index']);
        Route::post('/documentation/groups', [\App\Modules\Platform\Documentation\Http\PlatformDocGroupController::class, 'store']);
        Route::get('/documentation/groups/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocGroupController::class, 'show']);
        Route::put('/documentation/groups/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocGroupController::class, 'update']);
        Route::delete('/documentation/groups/{id}', [\App\Modules\Platform\Documentation\Http\PlatformDocGroupController::class, 'destroy']);
    });

    // ── Automations cockpit (ADR-430) ───────────────────────────
    Route::middleware(['module.active:platform.automations', 'platform.permission:manage_automations'])->group(function () {
        Route::get('/automations', [AutomationController::class, 'index']);
        Route::get('/automations/runs', [AutomationController::class, 'runs']);
        Route::post('/automations/run', [AutomationController::class, 'run']);
    });

    // ── Alert Center (ADR-438) ──────────────────────────────────
    Route::middleware(['module.active:platform.alerts'])->group(function () {
        Route::middleware(['platform.permission:view_alerts'])->group(function () {
            Route::get('/alerts', [PlatformAlertController::class, 'index']);
            Route::get('/alerts/count', [PlatformAlertController::class, 'count']);
        });

        Route::middleware(['platform.permission:manage_alerts'])->group(function () {
            Route::put('/alerts/{alert}/acknowledge', [PlatformAlertController::class, 'acknowledge']);
            Route::put('/alerts/{alert}/resolve', [PlatformAlertController::class, 'resolve']);
            Route::put('/alerts/{alert}/dismiss', [PlatformAlertController::class, 'dismiss']);
        });
    });

    // ── Email Platform (ADR-446) ──────────────────────────────────
    Route::middleware(['module.active:platform.email'])->group(function () {
        Route::middleware(['platform.permission:manage_email'])->group(function () {
            Route::get('/email/logs', [\App\Modules\Platform\Email\Http\EmailLogController::class, 'index']);
            Route::post('/email/logs/{id}/retry', [\App\Modules\Platform\Email\Http\EmailLogController::class, 'retry']);
            Route::get('/email/templates', [\App\Modules\Platform\Email\Http\EmailLogController::class, 'templates']);
            Route::get('/email/settings', [\App\Modules\Platform\Email\Http\EmailSettingsController::class, 'show']);
            Route::put('/email/settings', [\App\Modules\Platform\Email\Http\EmailSettingsController::class, 'update']);
            Route::post('/email/settings/test', [\App\Modules\Platform\Email\Http\EmailSettingsController::class, 'test']);
            Route::post('/email/settings/test-imap', [\App\Modules\Platform\Email\Http\EmailSettingsController::class, 'testImap']);
            Route::post('/email/settings/fetch-inbox', [\App\Modules\Platform\Email\Http\EmailSettingsController::class, 'fetchInbox']);

            // Per-admin email identity (ADR-450)
            Route::get('/email/identity', [\App\Modules\Platform\Email\Http\EmailSettingsController::class, 'showIdentity']);
            Route::put('/email/identity', [\App\Modules\Platform\Email\Http\EmailSettingsController::class, 'updateIdentity']);

            // Templates CRUD (ADR-446)
            Route::post('/email/templates/configurable', [\App\Modules\Platform\Email\Http\EmailTemplateController::class, 'store']);
            Route::get('/email/templates/configurable', [\App\Modules\Platform\Email\Http\EmailTemplateController::class, 'index']);
            Route::get('/email/templates/configurable/{key}', [\App\Modules\Platform\Email\Http\EmailTemplateController::class, 'show']);
            Route::put('/email/templates/configurable/{key}', [\App\Modules\Platform\Email\Http\EmailTemplateController::class, 'update']);
            Route::post('/email/templates/configurable/{key}/preview', [\App\Modules\Platform\Email\Http\EmailTemplateController::class, 'preview']);
            Route::post('/email/templates/configurable/{key}/test', [\App\Modules\Platform\Email\Http\EmailTemplateController::class, 'sendTest']);

            // Orchestration (ADR-446)
            Route::get('/email/orchestration', [\App\Modules\Platform\Email\Http\EmailOrchestrationController::class, 'index']);
            Route::post('/email/orchestration', [\App\Modules\Platform\Email\Http\EmailOrchestrationController::class, 'store']);
            Route::put('/email/orchestration/{id}', [\App\Modules\Platform\Email\Http\EmailOrchestrationController::class, 'update']);

            // Inbox (ADR-447)
            Route::get('/email/inbox', [\App\Modules\Platform\Email\Http\EmailInboxController::class, 'index']);
            Route::get('/email/inbox/{id}', [\App\Modules\Platform\Email\Http\EmailInboxController::class, 'show']);
            Route::post('/email/inbox/compose', [\App\Modules\Platform\Email\Http\EmailInboxController::class, 'compose']);
            Route::post('/email/inbox/{id}/reply', [\App\Modules\Platform\Email\Http\EmailInboxController::class, 'reply']);
            Route::post('/email/inbox/{id}/read', [\App\Modules\Platform\Email\Http\EmailInboxController::class, 'markRead']);
            Route::put('/email/inbox/{id}/status', [\App\Modules\Platform\Email\Http\EmailInboxController::class, 'updateStatus']);
        });

        // Inbound webhook (no auth — secured by webhook secret)
        Route::post('/email/inbound', [\App\Modules\Platform\Email\Http\EmailInboundController::class, 'store'])
            ->withoutMiddleware(['auth:platform', 'platform.permission:manage_email']);
    });

    }); // end platform.2fa
});
