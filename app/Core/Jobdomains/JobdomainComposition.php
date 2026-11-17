<?php

namespace App\Core\Jobdomains;

use App\Core\Audit\AuditLogger;
use App\Core\Modules\ModuleDependencyResolver;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-821 — the modules of a JobDomain in ONE place for the Platform administrator : compatible, started at
 * creation, recommended. Dependencies come from the module manifests (ModuleDependencyResolver) and are added
 * automatically ; compatibility stays stored on the module (`compatible_jobdomains_override`, its owner), the
 * commercial right stays the plan's (a start module is only activated when the plan includes it). Nothing is
 * applied to existing companies.
 */
final class JobdomainComposition
{
    /** Read model : every company module with its compatibility, role in the domain, dependencies and commercial status per plan. */
    public static function describe(Jobdomain $jd): array
    {
        $start = (array) $jd->default_modules;
        $recommended = (array) ($jd->recommended_modules ?? []);
        $startResolution = ModuleDependencyResolver::resolve($start);
        $plans = PlanRegistry::keys();
        $rows = [];
        foreach (ModuleRegistry::forScope('company') as $m) {
            if (str_starts_with($m->key, 'core.')) {
                continue;
            }
            $rows[] = [
                'key' => $m->key, 'name' => $m->name, 'requires' => ModuleDependencyResolver::requiresOf($m->key),
                'compatible' => self::isCompatible($m->key, $jd->key),
                'start' => in_array($m->key, $start, true),
                // ADR-821 : requis par un autre module du démarrage (même s'il est enregistré dans la liste)
                'start_dependency_of' => ModuleDependencyResolver::dependents($m->key, array_values(array_diff($start, [$m->key]))),
                'recommended' => in_array($m->key, $recommended, true),
                'commercial' => collect($plans)->mapWithKeys(fn ($p) => [$p => ModuleDependencyResolver::commercialStatus($m->key, $p)])->all(),
            ];
        }

        return ['modules' => $rows, 'plans' => $plans];
    }

    public static function isCompatible(string $moduleKey, string $jobdomainKey): bool
    {
        $m = ModuleRegistry::definitions()[$moduleKey] ?? null;
        $override = PlatformModule::where('key', $moduleKey)->first()?->compatible_jobdomains_override;
        $list = $override ?? $m?->compatibleJobdomains;

        return $list === null || in_array($jobdomainKey, $list, true);
    }

    /**
     * Save the composition. `start` and `recommended` are completed with their dependencies (never a module
     * without what it needs) ; every module used must be compatible. Returns what was added automatically.
     *
     * @param  array{compatible: list<string>, start: list<string>, recommended: list<string>}  $data
     */
    public static function save(Jobdomain $jd, array $data, ?int $actorId): array
    {
        $company = array_keys(array_filter(ModuleRegistry::definitions(), fn ($m) => $m->scope === 'company' && ! str_starts_with($m->key, 'core.')));
        $compatible = array_values(array_intersect($data['compatible'], $company));
        $start = ModuleDependencyResolver::resolve(array_values(array_intersect($data['start'], $company)));
        $recommended = ModuleDependencyResolver::resolve(array_values(array_diff(array_intersect($data['recommended'], $company), $start['all'])));
        foreach ([$start, $recommended] as $r) {
            if ($r['cycles'] !== [] || $r['unknown'] !== []) {
                throw ValidationException::withMessages(['start' => __('modules.dependency_inconsistent')]);
            }
        }
        // Un module utilisé (au démarrage, recommandé, ou dépendance) doit être compatible : ajouté à la compatibilité.
        $needed = array_values(array_unique(array_merge($start['all'], $recommended['all'])));
        $compatible = array_values(array_unique(array_merge($compatible, array_intersect($needed, $company))));

        // Jamais retirer un droit historique : un module encore actif dans une société de ce métier reste compatible.
        foreach ($company as $key) {
            if (! in_array($key, $compatible, true) && self::isCompatible($key, $jd->key)
                && \App\Core\Modules\CompanyModule::where('module_key', $key)->where('is_enabled_for_company', true)
                    ->whereIn('company_id', \App\Core\Models\Company::where('jobdomain_key', $jd->key)->select('id'))->exists()) {
                throw ValidationException::withMessages(['compatible' => __('modules.compatibility_in_use', ['module' => ModuleRegistry::definitions()[$key]->name])]);
            }
        }

        DB::transaction(function () use ($jd, $company, $compatible, $start, $recommended) {
            foreach ($company as $key) {
                $want = in_array($key, $compatible, true);
                if (self::isCompatible($key, $jd->key) === $want) {
                    continue;
                }
                $pm = PlatformModule::where('key', $key)->first();
                if (! $pm) {
                    continue;
                }
                $current = $pm->compatible_jobdomains_override ?? ModuleRegistry::definitions()[$key]->compatibleJobdomains
                    ?? Jobdomain::pluck('key')->all(); // universel → liste explicite de tous les métiers
                $next = $want ? array_values(array_unique(array_merge($current, [$jd->key]))) : array_values(array_diff($current, [$jd->key]));
                $pm->update(['compatible_jobdomains_override' => $next]);
            }
            $core = array_values(array_filter((array) $jd->default_modules, fn ($k) => str_starts_with($k, 'core.')));
            $jd->update(['default_modules' => array_values(array_unique(array_merge($core, $start['all']))),
                'recommended_modules' => array_values(array_diff($recommended['all'], $start['all']))]);
        });
        app(AuditLogger::class)->logPlatform('jobdomain.composition_updated', 'jobdomain', $jd->key, ['actorId' => $actorId,
            'metadata' => ['start' => $start['all'], 'recommended' => $recommended['all'], 'added' => array_keys($start['added'] + $recommended['added'])]]);

        return ['added' => $start['added'] + $recommended['added']];
    }
}
