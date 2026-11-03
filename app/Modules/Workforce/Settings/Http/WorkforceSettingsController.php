<?php

namespace App\Modules\Workforce\Settings\Http;

use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\Organization\OrgProfileRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-639 (W-B) — centre de paramètres RH : AGRÉGATEUR. Déclare les sections DISPONIBLES selon les
 * modules actifs + le contexte métier (market/jobdomain/profil). Ne stocke rien, ne calcule aucune
 * règle : les sections consomment les endpoints existants (config/breaks, work-time, leave, planning,
 * organisation…). La section Contract n'apparaît que si le module est actif ; Signature (non livré) jamais.
 */
class WorkforceSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = Company::withoutGlobalScopes()->findOrFail($request->attributes->get('company_id'));
        $user = $request->user();

        // E2.1 (ADR-691) — une section n'est listée que si l'utilisateur peut RÉELLEMENT la charger :
        // les onglets de configuration administrative (Contrats, Signature) exigent leur capacité propre,
        // sinon ils s'affichaient et déclenchaient un 403 au montage. general + worktime restent en
        // lecture légitime pour workforce.view (édition gardée par workforce.manage côté endpoint/UI).
        $canContract = $user->hasCompanyPermission($company, 'workforce_contract.view');
        $canSignature = $user->hasCompanyPermission($company, 'workforce_signature.admin');

        // PRODUCT-UX-3 (P2-B, ADR-683) : les onglets « lanceurs » (organization/leave/planning/
        // documents) et le doublon lecture-seule « rules » sont retirés. Les paramètres sont
        // administrés dans leur espace métier (Organisation, Congés, Temps/Planning, Documents) ;
        // les règles de temps de travail ont une SOURCE DE VÉRITÉ UNIQUE : l'onglet « worktime ».
        // Ne restent ici que les réglages RÉELLEMENT éditables sans espace métier dédié.
        $sections = [
            ['key' => 'general', 'available' => true],
            ['key' => 'worktime', 'available' => ModuleGate::isActive($company, 'workforce')],
            // W-CONTRACT-3B : section Contrats de travail — module actif ET capacité de lecture contrat.
            ['key' => 'contract', 'available' => ModuleGate::isActive($company, 'workforce_contract') && $canContract],
            // W-SIGNATURE-1 : section Signature électronique — module actif ET administration signature.
            ['key' => 'signature', 'available' => ModuleGate::isActive($company, 'workforce_signature') && $canSignature],
        ];

        return response()->json([
            'sections' => array_values(array_filter($sections, fn ($s) => $s['available'])),
            'context' => [
                'company_name' => $company->name,
                'market_key' => $company->market_key,
                'jobdomain_key' => $company->jobdomain_key,
                'org_profile_key' => $company->org_profile_key,
                'org_profile_label' => OrgProfileRegistry::label($company->jobdomain_key, $company->org_profile_key),
                // W-CONTRACT-3B : identité documentaire employeur (données Company existantes, aucune ressaisie — PP2).
                'employer_identity' => [
                    'name' => $company->name,
                    'siret' => $company->siret,
                    'naf_code' => $company->naf_code,
                    'legal_status' => $company->legalStatus?->name,
                    'address' => trim(implode(' ', array_filter([$company->address_street, $company->address_complement]))),
                    'city' => trim(implode(' ', array_filter([$company->address_postal_code, $company->address_city]))),
                    'country_code' => $company->address_country_code,
                ],
            ],
        ]);
    }
}
