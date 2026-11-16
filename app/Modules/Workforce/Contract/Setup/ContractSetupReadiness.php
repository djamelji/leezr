<?php

namespace App\Modules\Workforce\Contract\Setup;

use App\Core\Models\Company;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\ModuleReadiness;
use App\Modules\Workforce\Contract\Services\ContractTemplateService;

/**
 * ADR-805 — Contrats de travail. A contract is generated FROM a template : REQUIRED = access decided + at
 * least one PUBLISHED template available to the company (its own, or the applicable platform catalog —
 * ContractTemplateService::available, the same list the generator offers). RECOMMENDED : a default template.
 * Templates are managed in Documents → Modèles ; nothing is copied here.
 */
final class ContractSetupReadiness implements ModuleReadiness
{
    public function checks(Company $company, CompanyModuleSetup $setup): array
    {
        $published = self::published($company);

        return [
            ['key' => 'access_decided', 'ok' => in_array('access', $setup->completed_steps ?? [], true)],
            ['key' => 'contract_template_published', 'ok' => $published !== []],
            ['key' => 'contract_template_default', 'required' => false, 'ok' => collect($published)->contains('is_default', true)],
        ];
    }

    /** Mandatory settings = which templates are published (and their version) + the defaults. */
    public function settingsFingerprint(Company $company): ?string
    {
        return sha1(json_encode(collect(self::published($company))->sortBy('id')->map(fn ($t) => [$t['id'], $t['version'], $t['is_default']])->values()->all()));
    }

    public static function published(Company $company): array
    {
        return array_values(array_filter(ContractTemplateService::available($company), fn ($t) => $t['status'] === ContractTemplateService::STATUS_PUBLISHED && $t['is_active']));
    }
}
