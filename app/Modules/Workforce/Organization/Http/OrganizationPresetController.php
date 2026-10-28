<?php

namespace App\Modules\Workforce\Organization\Http;

use App\Http\Controllers\Controller;
use App\Modules\Workforce\Organization\OrganizationPresetApplication;
use App\Modules\Workforce\Organization\OrganizationPresetResolver;
use App\Modules\Workforce\Organization\OrganizationPresetService;
use App\Modules\Workforce\Organization\OrgProfileRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-636 (W-E) — API Company de l'organisation : profil, aperçu (read-only) et application
 * (transactionnelle, idempotente, non destructive) d'un preset organisationnel.
 */
class OrganizationPresetController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $preset = OrganizationPresetResolver::resolveFor($company);
        $lastApplied = OrganizationPresetApplication::withoutCompanyScope()
            ->where('company_id', $company->id)->latest()->first();

        return response()->json([
            'org_profile_key' => $company->org_profile_key,
            'org_profile_label' => OrgProfileRegistry::label($company->jobdomain_key, $company->org_profile_key),
            'available_profiles' => OrgProfileRegistry::forJobdomain($company->jobdomain_key),
            'has_preset' => (bool) $preset,
            'preset_key' => $preset?->key,
            'preset_label' => $preset?->label,
            'last_applied' => $lastApplied ? [
                'preset_key' => $lastApplied->preset_key,
                'preset_version' => $lastApplied->preset_version,
                'applied_at' => $lastApplied->created_at?->toISOString(),
            ] : null,
        ]);
    }

    public function setProfile(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $validated = $request->validate(['org_profile_key' => 'nullable|string|max:60']);
        $key = $validated['org_profile_key'] ?? null;

        if (! OrgProfileRegistry::isValid($company->jobdomain_key, $key)) {
            return response()->json(['message' => 'Invalid org_profile_key for this jobdomain.'], 422);
        }

        $company->update(['org_profile_key' => $key]); // jamais rétroactif : ne touche pas l'organisation existante

        return response()->json(['org_profile_key' => $company->org_profile_key]);
    }

    public function preview(Request $request): JsonResponse
    {
        // STRICTEMENT sans effet de bord.
        return response()->json(OrganizationPresetService::preview($request->attributes->get('company')));
    }

    public function apply(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $result = OrganizationPresetService::apply($company, $request->user()?->id);

        return response()->json($result);
    }
}
