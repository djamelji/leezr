<?php

namespace App\Modules\Workforce\Documents\Setup;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\GeneratedDocument;
use App\Core\Models\Company;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\ModuleDeactivationImpact;
use App\Core\Modules\Setup\ModuleReadiness;

/**
 * ADR-805 — Documents RH (registre des documents générés). No mandatory setting : the templates are the
 * contract module's (or the platform catalog), generation needs nothing else. REQUIRED : access decided.
 * RECOMMENDED : a role other than the owner can generate. Who sees WHOSE documents is not decided here :
 * `workforce_documents.view` = self + direct reports ; everyone = Workforce HR (`workforce.view`+`workforce.manage`) ;
 * documents embedding pay additionally need `workforce.compensation_read` (GeneratedDocumentAccess, ADR-771).
 */
final class DocumentsSetupReadiness implements ModuleDeactivationImpact, ModuleReadiness
{
    public function checks(Company $company, CompanyModuleSetup $setup): array
    {
        return [
            ['key' => 'access_decided', 'ok' => in_array('access', $setup->completed_steps ?? [], true)],
            ['key' => 'generator_assigned', 'required' => false, 'ok' => self::nonOwnerHolds($company, 'workforce_documents.generate')],
        ];
    }

    public function settingsFingerprint(Company $company): ?string
    {
        return null; // no mandatory setting
    }

    public function deactivationImpact(Company $company): array
    {
        // Issued documents stay stored ; each person keeps them in « Mon espace » (Workforce self-service route).
        $issued = GeneratedDocument::withoutGlobalScopes()->where('company_id', $company->id)->count();

        return $issued ? [['key' => 'issued_documents', 'count' => $issued, 'continues' => true]] : [];
    }

    public static function nonOwnerHolds(Company $company, string $permission): bool
    {
        return CompanyRole::query()->where('company_id', $company->id)->where('key', '!=', 'owner')
            ->whereHas('permissions', fn ($q) => $q->where('key', $permission))->exists();
    }
}
