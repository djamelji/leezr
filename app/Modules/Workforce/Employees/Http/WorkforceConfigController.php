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
        $spec = app(WorkforceRuleResolver::class)->resolveBreakRules($this->company($request));

        return response()->json(['domain' => 'breaks', 'rules' => $this->explain($spec)]);
    }

    /** PUT /workforce/config/breaks/{rule} — set a configurable break rule (guarded). */
    public function setBreak(Request $request, string $rule): JsonResponse
    {
        $validated = $request->validate(['value' => ['present']]);

        try {
            app(WorkforceRuleResolver::class)->setBreakRule($this->company($request), $rule, $validated['value']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->breaks($request);
    }

    /** GET /workforce/config/work-time — resolved work-time rules (hours, rest, overtime, night). */
    public function workTime(Request $request): JsonResponse
    {
        $spec = app(WorkforceRuleResolver::class)->resolveWorkTimeRules($this->company($request));

        return response()->json(['domain' => 'work_time', 'rules' => $this->explain($spec)]);
    }

    /** PUT /workforce/config/work-time/{rule} — set a configurable work-time rule (guarded). */
    public function setWorkTime(Request $request, string $rule): JsonResponse
    {
        $validated = $request->validate(['value' => ['present']]);

        try {
            app(WorkforceRuleResolver::class)->setWorkTimeRule($this->company($request), $rule, $validated['value']);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->workTime($request);
    }

    /** Flatten a resolved spec into the explainable, cockpit-ready shape. */
    private function explain(array $spec): array
    {
        return collect($spec)->map(fn ($r, $key) => [
            'key' => $key,
            'value' => $r['value'],
            'source' => $r['source'],              // market_law | convention | jobdomain | company | default | to_complete
            'reference' => $r['reference'],         // legal/conventional reference when applicable
            'mutability' => $r['mutability'],       // mandatory | configurable | to_complete
            'status' => $r['status'],               // configured | default | to_complete
            'effective_from' => $r['effective_from'],
            'conflicts' => $r['conflicts'] ?? [],   // less-favorable values that were ignored
            'editable' => $r['mutability'] === 'configurable',
        ])->values()->all();
    }

    private function company(Request $request): Company
    {
        return Company::withoutGlobalScopes()->findOrFail($request->attributes->get('company_id'));
    }
}
