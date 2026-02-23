<?php

namespace App\Core\Plans\Http;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-100: Public (no auth) endpoints for plan catalog and pricing preview.
 */
class PublicPlanController extends Controller
{
    /**
     * GET /api/public/plans
     * Returns all plan definitions + active jobdomains.
     */
    public function index(): JsonResponse
    {
        $jobdomains = Jobdomain::where('is_active', true)
            ->orderBy('label')
            ->get(['key', 'label', 'description']);

        return response()->json([
            'plans' => PlanRegistry::publicCatalog(),
            'jobdomains' => $jobdomains,
        ]);
    }

    /**
     * GET /api/public/plans/preview?jobdomain={key}&plan={key}
     * Returns modules available for a (jobdomain, plan) combination.
     * Mirrors EntitlementResolver gates without needing a Company instance.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'jobdomain' => ['required', 'string'],
            'plan' => ['required', 'string'],
        ]);

        $planKey = $request->query('plan');
        $jobdomainKey = $request->query('jobdomain');

        $planDef = PlanRegistry::definitions()[$planKey] ?? null;

        if (!$planDef) {
            return response()->json(['message' => 'Unknown plan.'], 422);
        }

        $jobdomain = Jobdomain::where('key', $jobdomainKey)
            ->where('is_active', true)
            ->first();

        if (!$jobdomain) {
            return response()->json(['message' => 'Unknown jobdomain.'], 422);
        }

        $defaultModules = $jobdomain->default_modules ?? [];
        $modules = [];

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            // Gate 1: Core modules always entitled
            if ($manifest->type === 'core') {
                $modules[] = ['key' => $key, 'name' => $manifest->name, 'source' => 'core'];

                continue;
            }

            // Gate 2: Plan check
            if ($manifest->minPlan !== null) {
                if (!PlanRegistry::meetsRequirement($planKey, $manifest->minPlan)) {
                    continue;
                }
            }

            // Gate 3: Jobdomain compatibility
            if ($manifest->compatibleJobdomains !== null) {
                if (!in_array($jobdomainKey, $manifest->compatibleJobdomains, true)) {
                    continue;
                }
            }

            // Gate 4: Available via jobdomain default_modules
            if (in_array($key, $defaultModules, true)) {
                $modules[] = ['key' => $key, 'name' => $manifest->name, 'source' => 'jobdomain'];
            }
        }

        return response()->json([
            'plan' => ['key' => $planKey, ...$planDef],
            'jobdomain' => ['key' => $jobdomain->key, 'label' => $jobdomain->label],
            'modules' => $modules,
        ]);
    }
}
