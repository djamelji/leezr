<?php

namespace App\Modules\Workforce\Signature\Setup;

use App\Core\Models\Company;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\ModuleDeactivationImpact;
use App\Core\Modules\Setup\ModuleReadiness;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\Providers\SignatureProviderManager;
use App\Modules\Workforce\Signature\Services\SignatureSettingsService;

/**
 * ADR-805 — Signatures électroniques. REQUIRED : access decided + a provider that actually RESOLVES for the
 * company (SignatureProviderManager — without one, no request can be sent ; in PROD there is no default).
 * RECOMMENDED : a provider that is not a test/dev-only one (ADR-765 honesty). The settings themselves stay in
 * Réglages → Signature électronique (workforce_signature.admin) ; the step only summarises them.
 */
final class SignatureSetupReadiness implements ModuleDeactivationImpact, ModuleReadiness
{
    public function checks(Company $company, CompanyModuleSetup $setup): array
    {
        $adapter = self::adapter($company);

        return [
            ['key' => 'access_decided', 'ok' => in_array('access', $setup->completed_steps ?? [], true)],
            ['key' => 'signature_provider_available', 'ok' => $adapter !== null],
            ['key' => 'signature_provider_production', 'required' => false, 'ok' => $adapter !== null && ! $adapter->isDevOnly()],
        ];
    }

    /** Mandatory settings = the RESOLVED values the requests are created with. */
    public function settingsFingerprint(Company $company): ?string
    {
        $s = app(SignatureSettingsService::class)->resolve($company->id);

        return sha1(json_encode([$s['provider'], $s['default_level'], $s['default_format'], $s['validity_days'], $s['reminder_days'], $s['default_order']]));
    }

    public function deactivationImpact(Company $company): array
    {
        // In-flight requests are NOT cancelled : signers keep their link, provider webhooks are still applied,
        // expiry still runs. Only the company-side follow-up (list, reminders, cancellation, evidence) and the
        // employee's « Mes signatures » pause until reactivation.
        $open = SignatureRequest::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereNotIn('status', SignatureRequest::TERMINAL_STATUSES)->count();

        return $open ? [['key' => 'signatures_in_progress', 'count' => $open, 'continues' => true]] : [];
    }

    public static function adapter(Company $company)
    {
        return SignatureProviderManager::adapterFor(app(SignatureSettingsService::class)->resolve($company->id)['provider']);
    }
}
