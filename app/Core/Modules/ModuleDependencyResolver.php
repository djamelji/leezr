<?php

namespace App\Core\Modules;

use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;

/**
 * ADR-821 — THE module dependency resolution, read from the module manifests (`requires`) only. Used by the
 * JobDomain (start modules), the commercial composition (plans) and the company activation / deactivation.
 * No second list of dependencies exists anywhere : this class only reads ModuleRegistry.
 *
 *  - resolve()      : transitive closure (DFS), no duplicates, cycles and unknown modules reported, each added
 *                     module with the modules that need it (for a human explanation) ;
 *  - dependents()   : modules (among a set) that need a module, transitively ;
 *  - planCoverage() : commercial coherence — a module included in a plan level needs its dependencies included
 *                     at the same level (a premium dependency never becomes free by accident) ;
 *  - forCompany()   : what an activation will do for a company (already active, to activate, blocked + reason).
 */
final class ModuleDependencyResolver
{
    /**
     * @param  list<string>  $keys  selected modules
     * @return array{selected: list<string>, added: array<string, list<string>>, all: list<string>, unknown: list<string>, cycles: list<list<string>>}
     */
    public static function resolve(array $keys): array
    {
        $defs = ModuleRegistry::definitions();
        $selected = array_values(array_unique($keys));
        $added = [];
        $unknown = [];
        $cycles = [];
        foreach ($selected as $root) {
            self::walk($root, [], $defs, $selected, $added, $unknown, $cycles);
        }

        return ['selected' => $selected, 'added' => array_map(fn ($by) => array_values(array_unique($by)), $added),
            'all' => array_values(array_unique(array_merge($selected, array_keys($added)))),
            'unknown' => array_values(array_unique($unknown)), 'cycles' => $cycles];
    }

    private static function walk(string $key, array $path, array $defs, array $selected, array &$added, array &$unknown, array &$cycles): void
    {
        if (in_array($key, $path, true)) {
            $cycles[] = array_merge(array_slice($path, array_search($key, $path, true)), [$key]);

            return;
        }
        $manifest = $defs[$key] ?? null;
        if (! $manifest) {
            $unknown[] = $key;

            return;
        }
        foreach ($manifest->requires as $req) {
            if (! in_array($req, $selected, true)) {
                $added[$req][] = $key;
            }
            self::walk($req, array_merge($path, [$key]), $defs, $selected, $added, $unknown, $cycles);
        }
    }

    /** Transitive requirements of one module (the module itself excluded). */
    public static function requiresOf(string $key): array
    {
        return array_values(array_diff(self::resolve([$key])['all'], [$key]));
    }

    /** @param list<string> $among  @return list<string> modules of $among needing $key, transitively */
    public static function dependents(string $key, array $among): array
    {
        return array_values(array_filter($among, fn ($m) => $m !== $key && in_array($key, self::requiresOf($m), true)));
    }

    // ── Commercial coherence (plans) ─────────────────────────────────────────────────────────────

    /** included | option | not_included for one module at one plan level (no module list on plans : read from the module). */
    public static function commercialStatus(string $key, string $planKey, ?array $override = null): string
    {
        $m = ModuleRegistry::definitions()[$key] ?? null;
        if (! $m) {
            return 'not_included';
        }
        if ($m->type === 'core') {
            return 'included';
        }
        $pm = PlatformModule::where('key', $key)->first();
        $minPlan = array_key_exists('min_plan', $override ?? []) ? $override['min_plan'] : ($pm?->min_plan_override ?? $m->minPlan);
        $addon = array_key_exists('addon_pricing', $override ?? []) ? $override['addon_pricing'] : $pm?->addon_pricing;
        $reaches = $minPlan === null || PlanRegistry::meetsRequirement($planKey, $minPlan);
        if (! $reaches) {
            return 'not_included';
        }

        return $addon !== null ? 'option' : 'included';
    }

    /**
     * Every plan level where a module is INCLUDED must include its dependencies too. An optional (paid) module
     * needs dependencies that are included or optional themselves. Returns the violations (empty = coherent).
     *
     * @param  array<string, array{min_plan?: ?string, addon_pricing?: mixed}>  $overrides  pending edits (validated before saving)
     * @return list<array{plan: string, module: string, status: string, dependency: string, dependency_status: string}>
     */
    public static function planCoverage(array $overrides = [], ?array $modules = null): array
    {
        $violations = [];
        $keys = $modules ?? array_keys(array_filter(ModuleRegistry::definitions(), fn ($m) => $m->scope === 'company'));
        foreach (PlanRegistry::keys() as $plan) {
            foreach ($keys as $key) {
                $status = self::commercialStatus($key, $plan, $overrides[$key] ?? null);
                if ($status === 'not_included') {
                    continue;
                }
                foreach (self::requiresOf($key) as $dep) {
                    $depStatus = self::commercialStatus($dep, $plan, $overrides[$dep] ?? null);
                    $covered = $status === 'included' ? $depStatus === 'included' : $depStatus !== 'not_included';
                    if (! $covered) {
                        $violations[] = ['plan' => $plan, 'module' => $key, 'status' => $status, 'dependency' => $dep, 'dependency_status' => $depStatus];
                    }
                }
            }
        }

        return $violations;
    }

    // ── Company activation ───────────────────────────────────────────────────────────────────────

    /**
     * What activating $key will do for a company : modules already active, modules to activate (with the
     * modules that need them), and blocking reasons (never an activation without a valid commercial right).
     */
    public static function forCompany(Company $company, string $key): array
    {
        $r = self::resolve([$key]);
        $active = fn (string $k) => ModuleGate::isActive($company, $k);
        $already = [];
        $toActivate = [];
        $blocked = [];
        foreach ($r['all'] as $k) {
            if ($active($k)) {
                $already[] = $k;

                continue;
            }
            $toActivate[] = $k;
            if (! ModuleGate::isEnabledGlobally($k)) {
                $blocked[] = ['module' => $k, 'reason' => 'unavailable'];

                continue;
            }
            $e = EntitlementResolver::check($company, $k);
            if (! $e['entitled']) {
                $blocked[] = ['module' => $k, 'reason' => $e['reason'] ?? 'not_available'];
            }
        }
        foreach ($r['unknown'] as $k) {
            $blocked[] = ['module' => $k, 'reason' => 'unknown_module'];
        }
        if ($r['cycles'] !== []) {
            $blocked[] = ['module' => $key, 'reason' => 'dependency_cycle'];
        }

        // Options payantes : les activer revient à les acheter (la facturation crée l'abonnement) — annoncé avant.
        $plan = \App\Core\Billing\CompanyEntitlements::planKey($company);
        $purchase = array_values(array_filter($toActivate, fn ($k) => self::commercialStatus($k, $plan) === 'option'));

        return ['module' => $key, 'already_active' => $already, 'to_activate' => $toActivate, 'purchase' => $purchase,
            'added' => array_intersect_key($r['added'], array_flip($toActivate)), 'blocked' => $blocked, 'can_activate' => $blocked === []];
    }

    /** Active modules of a company that would lose a dependency if $key were switched off. */
    public static function activeDependents(Company $company, string $key): array
    {
        $active = CompanyModule::where('company_id', $company->id)->where('is_enabled_for_company', true)->pluck('module_key')->all();

        return self::dependents($key, $active);
    }
}
