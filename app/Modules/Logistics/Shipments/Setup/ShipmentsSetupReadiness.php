<?php

namespace App\Modules\Logistics\Shipments\Setup;

use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Modules\Setup\CompanyModuleSetup;
use App\Core\Modules\Setup\ModuleDeactivationImpact;
use App\Core\Modules\Setup\ModuleReadiness;
use App\Modules\Workforce\Documents\Setup\DocumentsSetupReadiness;

/**
 * ADR-806 — Expéditions (pilote Logistique). The module has NO setting executed by the backend (no settings
 * schema, no settings panel, the status workflow is fixed by Shipment::TRANSITIONS) : the only decision is
 * WHO operates and WHO delivers. REQUIRED : access decided. RECOMMENDED : a role other than the owner can
 * see its assigned deliveries (`shipments.view_own`), otherwise assigned people cannot open « Mes livraisons ».
 */
final class ShipmentsSetupReadiness implements ModuleDeactivationImpact, ModuleReadiness
{
    public function checks(Company $company, CompanyModuleSetup $setup): array
    {
        return [
            ['key' => 'access_decided', 'ok' => in_array('access', $setup->completed_steps ?? [], true)],
            ['key' => 'delivery_assigned', 'required' => false, 'ok' => DocumentsSetupReadiness::nonOwnerHolds($company, 'shipments.view_own')],
            // ADR-820 : identifiant chauffeur associé à un champ de la société (recommandé : seule l'affectation en dépend)
            ['key' => 'data_mapped', 'required' => false, 'ok' => \App\Core\Modules\Data\ModuleDataNeeds::allMapped($company, 'logistics_shipments')],
        ];
    }

    public function settingsFingerprint(Company $company): ?string
    {
        return null; // no setting executed by the backend
    }

    public function deactivationImpact(Company $company): array
    {
        // Shipments are kept ; while the module is off nobody (drivers included) can update their status.
        $open = Shipment::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereNotIn('status', Shipment::TERMINAL_STATUSES)->count();

        return $open ? [['key' => 'shipments_in_progress', 'count' => $open, 'continues' => false]] : [];
    }
}
