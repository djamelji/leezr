<?php

namespace App\Modules\Platform\Companies\Creation;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\Modernization\VersionedCompanyInitializer;
use App\Core\Models\Company;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleCommercialPolicy;
use App\Core\Modules\ModuleDependencyResolver;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Plans\PlanRegistry;

/**
 * ADR-834 — what the Platform creation wizard may offer, computed by the EXISTING engines only :
 * commercial status (ModuleDependencyResolver), sellability (ModuleCommercialPolicy), global availability
 * (ModuleGate), pricing (ModuleQuoteCalculator), initial configuration (VersionedCompanyInitializer::plan).
 * The same computation validates the submission (CreatePlatformCompanyUseCase) : what the screen shows as
 * impossible is refused by the server.
 */
final class PlatformCompanyCreationCatalog
{
    public const SELECTABLE = ['included', 'option', 'with_dependency'];

    public static function options(): array
    {
        return [
            'jobdomains' => Jobdomain::where('is_active', true)->orderBy('label')->get()
                ->map(fn (Jobdomain $jd) => [
                    'key' => $jd->key,
                    'label' => $jd->label,
                    'description' => $jd->description,
                    'sector' => $jd->sector,
                    'offered_for_signup' => (bool) $jd->signup_available,
                ])->values()->all(),
            'plans' => array_map(fn ($p) => [
                'key' => $p['key'],
                'name' => $p['name'] ?? $p['key'],
                'level' => $p['level'] ?? 0,
                'price_monthly' => $p['price_monthly'] ?? 0,
                'price_yearly' => $p['price_yearly'] ?? 0,
                'trial_days' => (int) ($p['trial_days'] ?? 0),
                'is_free' => ($p['price_monthly'] ?? 0) <= 0 && ($p['price_yearly'] ?? 0) <= 0,
            ], PlanRegistry::publicCatalog()),
        ];
    }

    /**
     * Every company module compatible with the activity, classified for the plan :
     *  - base         : core + included by the activity → activated at creation, not a choice ;
     *  - included     : covered by the plan, free, optional ;
     *  - option       : paid add-on (billed by the billing engine, never at creation) ;
     *  - with_dependency : covered itself but needs a paid add-on (added with it) ;
     *  - plan_required : needs a higher plan → refused, `required_plan` tells which one ;
     *  - unavailable  : not commercialised / disabled platform-wide → refused.
     */
    public static function modules(string $jobdomainKey, string $planKey): array
    {
        $jd = Jobdomain::where('key', $jobdomainKey)->where('is_active', true)->firstOrFail();
        $defaults = (array) $jd->default_modules;
        $definitions = ModuleRegistry::forScope('company');
        // the entitlement authority of a NEW company of this activity and plan (same probe as classifyModules)
        $probe = new Company(['jobdomain_key' => $jobdomainKey, 'plan_key' => $planKey]);
        $probe->setRelation('jobdomain', $jd);
        $out = [];
        foreach ($definitions as $key => $m) {
            if ($m->type === 'internal' || $m->hidden) {
                continue;
            }
            $pm = PlatformModule::where('key', $key)->first();
            $compatible = $pm?->compatible_jobdomains_override ?? $m->compatibleJobdomains;
            if ($compatible !== null && ! in_array($jobdomainKey, $compatible, true)) {
                continue; // incompatible with the activity : never shown as a choice
            }
            $status = ModuleDependencyResolver::commercialStatus($key, $planKey);
            $sellable = $pm?->addon_pricing === null || in_array(ModuleCommercialPolicy::statusFor($key), ModuleCommercialPolicy::SELLABLE_STATUSES, true);
            $entitlement = EntitlementResolver::check($probe, $key, $pm);
            $class = match (true) {
                ! ModuleGate::isEnabledGlobally($key), ! $sellable => 'unavailable',
                $status === 'not_included' => 'plan_required',
                $m->type === 'core' => 'base',
                ! $entitlement['entitled'] => 'unavailable', // e.g. not offered to this activity outside its defaults
                $status === 'included' && in_array($entitlement['source'], ['jobdomain', 'jobdomain_dependency'], true) => 'base',
                default => $status, // included | option | with_dependency
            };
            $minPlan = $pm?->min_plan_override ?? $m->minPlan;
            $out[] = [
                'key' => $key,
                'name' => $pm?->display_name_override ?? $m->name,
                'description' => $m->description,
                'icon' => $m->iconRef,
                'class' => $class,
                'recommended' => in_array($key, $defaults, true),
                'requires' => array_values(ModuleDependencyResolver::requiresOf($key)),
                'required_plan' => $class === 'plan_required' && $minPlan ? ['key' => $minPlan, 'name' => PlanRegistry::definitions()[$minPlan]['name'] ?? $minPlan] : null,
                'price' => $class === 'option' && $pm ? ModuleQuoteCalculator::computeAmount($pm, $planKey) : 0,
            ];
        }
        $names = array_column($out, 'name', 'key');
        foreach ($out as &$row) {
            $row['requires'] = array_map(fn ($k) => ['key' => $k, 'name' => $names[$k] ?? ($definitions[$k]->name ?? $k)], $row['requires']);
        }

        return $out;
    }

    /**
     * Initial configuration that creation will apply, in business terms (no technical keys as choices).
     */
    public static function initialization(string $jobdomainKey, ?string $marketKey, string $planKey): array
    {
        $versioned = VersionedCompanyInitializer::shouldUse($jobdomainKey, $marketKey);
        if (! $versioned) {
            $jd = Jobdomain::where('key', $jobdomainKey)->firstOrFail();

            return ['path' => 'historical', 'presets' => [], 'historical' => [
                'roles' => array_values(array_map(fn ($r) => $r['name'] ?? '', (array) $jd->default_roles)),
                'fields' => count((array) $jd->default_fields),
                'documents' => count((array) $jd->default_documents),
            ]];
        }
        $presets = [];
        foreach (VersionedCompanyInitializer::plan($jobdomainKey, $marketKey, $planKey)['presets'] as $rec) {
            if (! $rec['usable'] || $rec['effective_status'] !== 'published') {
                continue;
            }
            $counts = [];
            $labels = [];
            foreach ($rec['items'] as $item) {
                $type = preg_replace('/[:@].*$/', '', $item['key']);
                $counts[$type] = ($counts[$type] ?? 0) + 1;
                if (in_array($rec['kind'], ['rbac', 'organization'], true)) {
                    $labels[] = ['label' => $item['label'], 'automatic' => $item['decision'] === VersionedCompanyInitializer::AUTO,
                        'owner' => $item['key'] === 'role:owner'];
                }
            }
            $presets[] = [
                'kind' => $rec['kind'],
                'label' => $rec['label'],
                'version' => $rec['effective_version'],
                'counts' => $counts,
                'items' => $labels,
                // what the operator may decide now ; requirements are always the company's own decision (ADR-820)
                'confirmable' => in_array($rec['kind'], ['planning', 'organization'], true),
            ];
        }

        return ['path' => 'versioned', 'presets' => $presets, 'historical' => null];
    }
}
