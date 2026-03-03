<?php

use App\Modules\Platform\Companies\Http\CompanyController;
use App\Modules\Platform\Companies\Http\CompanyModuleController;
use App\Modules\Platform\Companies\Http\CompanyUserController;
use App\Modules\Platform\Billing\Http\BillingConfigController;
use App\Modules\Platform\Billing\Http\PlatformBillingController;
use App\Modules\Platform\Billing\Http\PlatformInvoiceMutationController;
use App\Modules\Platform\Billing\Http\PlatformAdvancedMutationController;
use App\Modules\Platform\Billing\Http\PaymentModuleController;
use App\Modules\Platform\Billing\Http\PaymentMethodRuleController;
use App\Modules\Platform\Billing\Http\PlatformBillingPolicyController;
use App\Modules\Platform\Billing\Http\PlatformFinancialController;
use App\Modules\Platform\Billing\Http\PlatformBillingWidgetsController;
use App\Modules\Platform\Dashboard\Http\DashboardWidgetController;
use App\Modules\Platform\Dashboard\Http\DashboardLayoutController;
use App\Modules\Platform\Plans\Http\PlanCrudController;
use App\Modules\Platform\Fields\Http\FieldActivationController;
use App\Modules\Platform\Fields\Http\FieldDefinitionController;
use App\Modules\Platform\Jobdomains\Http\JobdomainController;
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
use App\Modules\Platform\Audit\Http\PlatformAuditLogController;
use App\Modules\Platform\Security\Http\SecurityAlertController;
use App\Modules\Infrastructure\Navigation\Http\NavController;
use App\Modules\Infrastructure\Theme\Http\PlatformThemePreferenceController;
use App\Modules\Infrastructure\AdminAuth\Http\PlatformAuthController;
use App\Modules\Infrastructure\AdminAuth\Http\PlatformPasswordResetController;
use Illuminate\Support\Facades\Route;

// Platform auth — public (throttled)
Route::post('/login', [PlatformAuthController::class, 'login'])
    ->middleware('throttle:15,1');
Route::post('/forgot-password', [PlatformPasswordResetController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/reset-password', [PlatformPasswordResetController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

// Authenticated platform routes
Route::middleware(['auth:platform', 'session.governance'])->group(function () {
    Route::get('/me', [PlatformAuthController::class, 'me']);
    Route::get('/nav', [NavController::class, 'platform']);
    Route::post('/logout', [PlatformAuthController::class, 'logout']);

    // Theme preference (ADR-159, no module gate — platform admins always have it)
    Route::put('/theme-preference', [PlatformThemePreferenceController::class, 'update']);

    // Companies
    Route::middleware(['module.active:platform.companies', 'platform.permission:manage_companies'])->group(function () {
        Route::get('/companies', [CompanyController::class, 'index']);
        Route::get('/companies/{id}', [CompanyController::class, 'show']);
        Route::put('/companies/{id}/suspend', [CompanyController::class, 'suspend']);
        Route::put('/companies/{id}/reactivate', [CompanyController::class, 'reactivate']);
        Route::put('/companies/{id}/plan', [CompanyController::class, 'updatePlan']);
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
        Route::get('/billing/invoices/{id}', [PlatformBillingController::class, 'invoiceDetail']);
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
    });

    // Dashboard engine (ADR-149 D4e.3)
    Route::middleware(['module.active:platform.dashboard'])->group(function () {
        Route::get('/dashboard/widgets/catalog', [DashboardWidgetController::class, 'catalog']);
        Route::post('/dashboard/widgets/data', [DashboardWidgetController::class, 'batchResolve']);
        Route::get('/dashboard/layout', [DashboardLayoutController::class, 'show']);
        Route::put('/dashboard/layout', [DashboardLayoutController::class, 'update']);
        Route::get('/dashboard/layout/presets', [DashboardLayoutController::class, 'presets']);
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
        Route::get('/translations/{locale}/{namespace}', [TranslationController::class, 'show']);
        Route::put('/translations/{id}', [TranslationController::class, 'update']);
        Route::post('/translations/import-preview', [TranslationController::class, 'importPreview']);
        Route::post('/translations/import-apply', [TranslationController::class, 'importApply']);
        Route::get('/translations/export/{locale}', [TranslationController::class, 'export']);

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

    // Heartbeat (session keepalive — governance middleware handles TTL header)
    Route::post('/heartbeat', fn () => response()->noContent());

    // Job Domains (CRUD)
    Route::middleware(['module.active:platform.jobdomains', 'platform.permission:manage_jobdomains'])->group(function () {
        Route::get('/jobdomains', [JobdomainController::class, 'index']);
        Route::get('/jobdomains/{id}', [JobdomainController::class, 'show']);
        Route::post('/jobdomains', [JobdomainController::class, 'store']);
        Route::put('/jobdomains/{id}', [JobdomainController::class, 'update']);
        Route::delete('/jobdomains/{id}', [JobdomainController::class, 'destroy']);
    });
});
