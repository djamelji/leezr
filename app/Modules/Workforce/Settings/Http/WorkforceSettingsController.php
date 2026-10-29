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

        // Une section = {key, available} ; `available` pilote l'affichage (modules réellement actifs).
        $sections = [
            ['key' => 'general', 'available' => true],
            ['key' => 'organization', 'available' => true],
            ['key' => 'worktime', 'available' => ModuleGate::isActive($company, 'workforce')],
            ['key' => 'leave', 'available' => ModuleGate::isActive($company, 'workforce_leave')],
            ['key' => 'planning', 'available' => ModuleGate::isActive($company, 'workforce_planning')],
            ['key' => 'documents', 'available' => ModuleGate::isActive($company, 'workforce_documents')],
            // W-CONTRACT-3B : section Contrats de travail — visible UNIQUEMENT si le module Contract est actif.
            ['key' => 'contract', 'available' => ModuleGate::isActive($company, 'workforce_contract')],
            // W-SIGNATURE-1 : section Signature électronique — visible UNIQUEMENT si le module Signature est actif.
            ['key' => 'signature', 'available' => ModuleGate::isActive($company, 'workforce_signature')],
            ['key' => 'rules', 'available' => ModuleGate::isActive($company, 'workforce')],
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
