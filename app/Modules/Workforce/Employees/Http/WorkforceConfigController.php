<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Models\Company;
use App\Core\Workforce\Services\WorkforceRuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workforce configuration cockpit (Workforce 2.0 §8) — exposes the EXPLAINABLE resolved rules
 * so a Company admin can see each rule's effective value, provenance, legal/configurable status,
 * effective date and an explanation, and edit the configurable ones. Mandatory (legal) rules are
 * protected server-side (setBreakRule throws). Permissions are enforced by the route middleware
 * (read: workforce.view, write: workforce.manage) — never by v-can alone (ANO-014).
 */
class WorkforceConfigController
{
    /** GET /workforce/config/breaks — resolved break rules with provenance + mutability. */
    public function breaks(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $spec = app(WorkforceRuleResolver::class)->resolveBreakRules($company);

        return response()->json([
            'domain' => 'breaks',
            'rules' => collect($spec)->map(fn ($r, $key) => [
                'key' => $key,
                'value' => $r['value'],
                'source' => $r['source'],              // market_law | company | default | to_complete
                'reference' => $r['reference'],         // legal reference when applicable
                'mutability' => $r['mutability'],       // mandatory | configurable | to_complete
                'status' => $r['status'],               // configured | default | to_complete
                'effective_from' => $r['effective_from'],
                'editable' => $r['mutability'] === 'configurable',
            ])->values(),
        ]);
    }

    /** PUT /workforce/config/breaks/{rule} — set a configurable break rule (guarded). */
    public function setBreak(Request $request, string $rule): JsonResponse
    {
        $validated = $request->validate([
            'value' => ['present'], // nullable allowed (clears to default); type depends on rule
        ]);

        $company = $this->company($request);

        try {
            app(WorkforceRuleResolver::class)->setBreakRule($company, $rule, $validated['value']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Return the refreshed, explainable spec so the cockpit updates in place.
        return $this->breaks($request);
    }

    private function company(Request $request): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($request->attributes->get('company_id'));
    }
}
