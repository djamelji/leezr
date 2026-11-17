<?php

namespace App\Core\Jobdomains\Modernization;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\AuditLogger;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\EntitlementResolver;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleRegistry;
use App\Core\Presets\PresetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-797 / ADR-798 — versioned initialisation of a NEW company : activity → recommendations (modules
 * classified, versioned presets) → preview → application of the allowed choices → later customisation.
 *
 * Behind `jobdomains.versioned_init.enabled` (off) : JobdomainGate::assignToCompany stays the creation
 * path ; turning the flag off again is the rollback for future creations (companies already created stay
 * ordinary companies). Rules :
 *  - modules : only those the company is ENTITLED to without purchase are activated (core, included by the
 *    domain, and their dependencies) ; paid / plan-gated ones stay optional ; incompatible ones refused ;
 *  - RBAC : non-sensitive roles automatically ; administrative / sensitive roles only when explicitly
 *    confirmed ; never on an existing company ;
 *  - catalog (ADR-820) : the starting LIBRARY — fields / document types made available, never required ;
 *  - requirements : explicit decision only (an obligation is configured by the company, never implied by
 *    the domain) ; the company is created with the POSITION authority : RBAC roles never define what is
 *    required from a person (ADR-820) ;
 *  - planning / organisation : explicit decisions only.
 *
 * ADR-820 — this is THE creation path for every active domain with a published RBAC preset (owner role) and
 * a published catalog ; `jobdomains.versioned_init.enabled=false` (or a domain missing from a non-`*`
 * allow-list) is the rollback to JobdomainGate::assignToCompany.
 */
final class VersionedCompanyInitializer
{
    public const AUTO = 'automatic';
    public const EXPLICIT = 'explicit_decision';

    public static function plan(string $jobdomainKey, ?string $marketKey, ?string $planKey = null): array
    {
        $jd = Jobdomain::where('key', $jobdomainKey)->firstOrFail();
        $presets = [];
        foreach (PresetService::recommendationsFor($jobdomainKey, $marketKey) as $rec) {
            $model = $rec['usable'] ? PresetService::find($rec['kind'], $rec['effective_id']) : null;
            $items = $model ? PresetService::adapter($rec['kind'])->items(PresetService::content($model)) : [];
            $presets[] = $rec + ['items' => array_map(fn ($k, $i) => ['key' => $k, 'label' => $i['name'] ?? $i['code'] ?? $i['title'] ?? $k,
                'decision' => self::decision($rec['kind'], $i)], array_keys($items), $items)];
        }

        return ['jobdomain' => ['key' => $jd->key, 'label' => $jd->label], 'market_key' => $marketKey,
            'modules' => self::classifyModules((array) $jd->default_modules, $jobdomainKey, $planKey), 'presets' => $presets,
            'operational' => (bool) config('jobdomains.versioned_init.enabled')];
    }

    /** Automatic vs explicit decision of one preset item at creation. */
    public static function decision(string $kind, array $item): string
    {
        return match ($kind) {
            'rbac' => self::sensitiveRole($item) ? self::EXPLICIT : self::AUTO,
            'catalog' => self::AUTO, // ADR-820 : bibliothèque = disponible, jamais exigé
            default => self::EXPLICIT, // planning, organisation
        };
    }

    public static function sensitiveRole(array $role): bool
    {
        static $admin = null;
        $admin ??= CompanyPermission::where('is_admin', true)->pluck('key')->all();

        return (bool) ($role['is_administrative'] ?? false) || array_intersect($role['permissions'] ?? ModuleRegistry::resolveBundles($role['bundles'] ?? []), $admin) !== [];
    }

    /**
     * Modules recommended by the domain, classified for a company of this domain / plan :
     * core (included by the platform) · included (by the domain, no purchase) · paid_optional (plan or
     * add-on needed : never bought here) · incompatible · unavailable. Dependencies are listed per module.
     */
    public static function classifyModules(array $keys, string $jobdomainKey, ?string $planKey = null): array
    {
        $probe = new Company(['jobdomain_key' => $jobdomainKey, 'plan_key' => $planKey ?? 'starter']);
        $out = [];
        foreach (array_values($keys) as $key) {
            $m = ModuleRegistry::definitions()[$key] ?? null;
            $e = $m ? EntitlementResolver::check($probe, $key) : ['entitled' => false, 'source' => null, 'reason' => 'unknown_module'];
            $class = match (true) {
                ! $m || ! ModuleGate::isEnabledGlobally($key) => 'unavailable',
                $e['reason'] === 'incompatible_jobdomain' => 'incompatible',
                ! $e['entitled'] => 'paid_optional',
                $e['source'] === 'core' => 'core',
                default => 'included',
            };
            $out[] = ['key' => $key, 'name' => $m?->name ?? $key, 'class' => $class, 'reason' => $e['reason'], 'min_plan' => $m?->minPlan,
                'exists' => $m !== null, 'globally_enabled' => $m !== null && ModuleGate::isEnabledGlobally($key),
                'activated_at_creation' => in_array($class, ['core', 'included'], true),
                'requires' => $m ? array_values(ModuleActivationEngine::collectTransitiveRequires($key)) : []];
        }

        return $out;
    }

    /**
     * ADR-808 — the single switch read by company creation. Versioned ONLY when the flag is on, the jobdomain is
     * in the allow-list AND a usable RBAC preset provides the owner role ; otherwise the historical path (an
     * absent / incomplete preset never produces a company without administration).
     */
    public static function shouldUse(string $jobdomainKey, ?string $marketKey): bool
    {
        $allowed = (array) config('jobdomains.versioned_init.jobdomains', []);
        if (! config('jobdomains.versioned_init.enabled') || ! (in_array('*', $allowed, true) || in_array($jobdomainKey, $allowed, true))) {
            return false;
        }
        $recs = collect(PresetService::recommendationsFor($jobdomainKey, $marketKey));
        $catalog = $recs->firstWhere('kind', 'catalog');
        if (! $catalog || ! $catalog['usable'] || $catalog['effective_status'] !== 'published') {
            return false; // ADR-820 : sans bibliothèque publiée, une société arriverait vide → chemin historique
        }
        $rbac = $recs->firstWhere('kind', 'rbac');
        // ADR-808.1 : a version pinned then archived is « usable » for recommendations but cannot be APPLIED
        // (PresetService::apply) — it would break the registration : historical path instead.
        if (! $rbac || ! $rbac['usable'] || $rbac['effective_status'] !== 'published') {
            return false;
        }
        $roles = PresetService::content(PresetService::find('rbac', $rbac['effective_id']))['roles'] ?? [];

        return collect($roles)->contains(fn ($r) => ($r['key'] ?? null) === 'owner');
    }

    /**
     * Full versioned creation of a NEW company (alternative to assignToCompany).
     *
     * @param  array{rbac?: list<string>, planning?: bool, organization?: bool}  $confirm  explicit decisions (role keys / families)
     * @param  bool  $simulation  only inside a transaction that the caller rolls back (parity measurement)
     */
    public static function initializeNew(Company $company, string $jobdomainKey, array $confirm, ?int $actorId, bool $simulation = false): array
    {
        if (! config('jobdomains.versioned_init.enabled') && ! ($simulation && DB::transactionLevel() > 0)) {
            throw ValidationException::withMessages(['versioned_init' => 'Versioned initialisation is prepared but not operational (jobdomains.versioned_init.enabled).']);
        }
        if (CompanyRole::where('company_id', $company->id)->exists() || CompanyModuleActivationReason::where('company_id', $company->id)->exists()) {
            throw ValidationException::withMessages(['company' => 'Versioned initialisation only applies to a NEW company (existing companies are never re-initialised).']);
        }
        $jd = Jobdomain::where('key', $jobdomainKey)->where('is_active', true)->firstOrFail();

        return DB::transaction(function () use ($company, $jd, $confirm, $actorId) {
            $company->update(['jobdomain_key' => $jd->key]);
            $company->jobdomains()->sync([$jd->id]);
            $summary = ['modules' => self::activateModules($company, (array) $jd->default_modules), 'presets' => []];

            foreach (PresetService::recommendationsFor($jd->key, $company->market_key) as $rec) {
                if (! $rec['usable']) {
                    continue;
                }
                if ($rec['effective_status'] !== 'published') { // ADR-808.1 : pinned archived version — never applied
                    $summary['presets'][$rec['kind']] = ['skipped' => 'version_not_published'];

                    continue;
                }
                $preset = PresetService::find($rec['kind'], $rec['effective_id']);
                $content = PresetService::content($preset);
                $items = PresetService::adapter($rec['kind'])->items($content);
                $selection = match ($rec['kind']) {
                    'rbac' => array_values(array_filter(array_keys($items), fn ($k) => ! self::sensitiveRole($items[$k]) || in_array(substr($k, 5), $confirm['rbac'] ?? [], true))),
                    'catalog' => null, // tous les éléments « inclus » ; les suggestions ne sont jamais activées
                    default => ($confirm[$rec['kind']] ?? false) ? null : false,
                };
                if ($selection === false) {
                    $summary['presets'][$rec['kind']] = ['skipped' => 'explicit_decision_not_taken'];

                    continue;
                }
                // Un rôle sensible confirmé est créé à l'état `requires_confirmation` : sélection explicite obligatoire.
                $summary['presets'][$rec['kind']] = PresetService::apply($company, $preset, $selection, 'versioned_init', $actorId);
                if ($rec['kind'] === 'rbac') {
                    self::finishRoles($company, $items);
                }
            }
            // ADR-820 : modèle cible — le poste (et les règles explicites de la société) décide des exigences,
            // jamais le rôle d'accès. Nouvelle société : aucune personne, donc aucune perte possible.
            \App\Core\Documents\CompanyDocumentSetting::forCompany($company->id);
            DB::table('company_document_settings')->where('company_id', $company->id)
                ->update(['requirements_authority' => \App\Core\Requirements\RequirementAuthority::POSITION, 'requirements_authority_at' => now(), 'requirements_authority_by' => $actorId, 'updated_at' => now()]);
            \App\Core\Requirements\RequirementAuthority::flush($company->id);
            self::cloneCompanyDashboard($company, $jd);
            $summary['pending_access'] = self::holdOperationalAccess($company, $summary['modules']['activated']);
            app(AuditLogger::class)->logCompany($company->id, 'company.versioned_initialized', 'company', (string) $company->id, ['actorId' => $actorId,
                'metadata' => ['jobdomain' => $jd->key, 'presets' => array_keys($summary['presets']), 'modules' => count($summary['modules']['activated'])]]);

            return $summary;
        });
    }

    private static function activateModules(Company $company, array $recommended): array
    {
        $classes = collect(self::classifyModules($recommended, (string) $company->jobdomain_key, $company->plan_key))->keyBy('key');
        $activated = [];
        foreach ($classes->where('activated_at_creation', true) as $key => $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => $key, 'reason' => CompanyModuleActivationReason::REASON_DIRECT, 'source_module_key' => null]);
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['is_enabled_for_company' => true]);
            $activated[] = $key;
        }
        foreach ($classes->where('activated_at_creation', true) as $key => $m) {
            foreach (ModuleActivationEngine::collectTransitiveRequires($key) as $req) {
                if (! in_array($req, $recommended, true) && ModuleGate::isEnabledGlobally($req)) {
                    CompanyModuleActivationReason::firstOrCreate(['company_id' => $company->id, 'module_key' => $req, 'reason' => CompanyModuleActivationReason::REASON_REQUIRED, 'source_module_key' => $key]);
                    CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $req], ['is_enabled_for_company' => true]);
                    $activated[] = $req;
                }
            }
        }

        return ['activated' => array_values(array_unique($activated)), 'not_activated' => $classes->where('activated_at_creation', false)->map(fn ($m) => $m['class'])->all()];
    }

    /** Roles created at creation from the platform preset are system roles (as historically) ; owner gets the owner role ; per-role dashboards. */
    private static function finishRoles(Company $company, array $items): void
    {
        foreach ($items as $role) {
            $r = CompanyRole::where('company_id', $company->id)->where('key', $role['key'])->first();
            if (! $r) {
                continue;
            }
            $r->forceFill(['is_system' => true])->save();
            if (! empty($role['dashboard_widgets'])) {
                \App\Modules\Dashboard\CompanyDashboardLayout::updateOrCreate(['company_id' => $company->id, 'user_id' => null, 'company_role_id' => $r->id],
                    ['layout_json' => JobdomainGate::buildLayoutFromWidgetKeys($role['dashboard_widgets'])]);
            }
        }
        $owner = CompanyRole::where('company_id', $company->id)->where('key', 'owner')->first();
        if ($owner) {
            $company->memberships()->where('role', 'owner')->whereNull('company_role_id')->update(['company_role_id' => $owner->id]);
        }
    }

    /**
     * ADR-808 — the owner role administers the company at once ; the OPERATIONAL roles pre-configured by the
     * preset keep their module rights stored but NOT effective until the module's assistant confirms the access
     * (company_module_setups.pending_access) — ADR-808.1 : core modules included, confirmed per role.
     */
    private static function holdOperationalAccess(Company $company, array $activated): array
    {
        $held = [];
        foreach ($activated as $key) {
            // ADR-808.1 : every activated module, core included (no implicit right for operational roles) ;
            // modules without assistant are confirmed per role in Équipe → Rôles.
            $pending = \App\Core\Modules\Setup\PendingModuleAccess::snapshot($company, $key);
            \App\Core\Modules\Setup\CompanyModuleSetup::query()->firstOrCreate(['company_id' => $company->id, 'module_key' => $key],
                ['status' => \App\Core\Modules\Setup\CompanyModuleSetup::TO_CONFIGURE, 'completed_steps' => [], 'pending_access' => $pending ?: null]);
            $held[$key] = array_keys($pending);
        }

        return $held;
    }


    private static function cloneCompanyDashboard(Company $company, Jobdomain $jd): void
    {
        $default = \App\Modules\Dashboard\JobdomainDashboardDefault::where('jobdomain_id', $jd->id)->first();
        if ($default && ! \App\Modules\Dashboard\CompanyDashboardLayout::where('company_id', $company->id)->whereNull('user_id')->whereNull('company_role_id')->exists()) {
            \App\Modules\Dashboard\CompanyDashboardLayout::create(['company_id' => $company->id, 'user_id' => null, 'layout_json' => $default->layout_json]);
        }
    }
}
