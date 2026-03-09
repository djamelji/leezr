<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Plans\PlanRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-300: Public (no auth) endpoint for addon module catalog.
 */
class PublicAddonController extends Controller
{
    /**
     * GET /api/public/addons?jobdomain={key}&plan={key}&market={key}
     *
     * Returns modules with addon_pricing that are available for the given
     * (jobdomain, plan) combination. Excludes modules already included
     * in the jobdomain's default_modules.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'jobdomain' => ['required', 'string', 'exists:jobdomains,key'],
            'plan' => ['required', 'string'],
            'market' => ['sometimes', 'string', 'exists:markets,key'],
        ]);

        $planKey = $request->query('plan');
        $jobdomainKey = $request->query('jobdomain');

        $jobdomain = Jobdomain::where('key', $jobdomainKey)
            ->where('is_active', true)
            ->first();

        if (! $jobdomain) {
            return response()->json(['addons' => [], 'currency' => 'EUR']);
        }

        $defaultModules = $jobdomain->default_modules ?? [];
        $addons = [];

        foreach (ModuleRegistry::forScope('company') as $key => $manifest) {
            // Skip core/internal modules
            if (in_array($manifest->type, ['core', 'internal'], true)) {
                continue;
            }

            // Skip modules already included in jobdomain defaults
            if (in_array($key, $defaultModules, true)) {
                continue;
            }

            // Plan check
            if ($manifest->minPlan !== null) {
                if (! PlanRegistry::meetsRequirement($planKey, $manifest->minPlan)) {
                    continue;
                }
            }

            // Jobdomain compatibility
            if ($manifest->compatibleJobdomains !== null) {
                if (! in_array($jobdomainKey, $manifest->compatibleJobdomains, true)) {
                    continue;
                }
            }

            // Must have addon_pricing and be globally enabled
            $pm = PlatformModule::where('key', $key)
                ->where('is_enabled_globally', true)
                ->first();

            if (! $pm || $pm->addon_pricing === null) {
                continue;
            }

            $amount = ModuleQuoteCalculator::computeAmount($pm, $planKey);

            $addons[] = [
                'key' => $key,
                'name' => $pm->display_name_override ?? $manifest->name,
                'description' => $manifest->description ?? '',
                'price' => $amount,
                'icon' => $manifest->iconRef ?? null,
            ];
        }

        // Resolve currency from market
        $market = Market::where('key', $request->query('market'))->first();
        $currency = $market?->currency ?? 'EUR';

        return response()->json([
            'addons' => $addons,
            'currency' => $currency,
        ]);
    }
}
