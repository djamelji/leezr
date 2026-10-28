<?php

namespace App\Modules\Workforce\Onboarding;

use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Organization\OrganizationPresetResolver;
use App\Modules\Workforce\Organization\OrgProfileRegistry;
use Illuminate\Support\Facades\DB;

/**
 * ADR-637 (W-F) — orchestration de l'onboarding RH : état persisté + CONTEXTE (étapes applicables selon
 * modules actifs, organisation/collaborateurs existants, profil suggéré). Non destructif : réutilise W-E
 * pour l'organisation ; ne crée aucun second moteur de règles/RBAC. Reprise multi-navigateur (serveur).
 */
class WorkforceOnboardingService
{
    /** Étapes de base (toujours) ; leave/worktime conditionnées par les modules actifs. */
    public const BASE_STEPS = ['company', 'organization', 'roles', 'first_employee', 'done'];

    public static function applicableSteps(Company $company): array
    {
        $steps = ['company', 'organization', 'roles'];
        if (ModuleGate::isActive($company, 'workforce_leave')) {
            $steps[] = 'leave';
        }
        if (ModuleGate::isActive($company, 'workforce')) {
            $steps[] = 'worktime';
        }
        $steps[] = 'first_employee';
        $steps[] = 'done';

        return $steps;
    }

    public static function stateWithContext(Company $company): array
    {
        $state = static::ensure($company);
        $deptCount = Department::withoutCompanyScope()->where('company_id', $company->id)->count();
        $roleCount = JobRole::withoutCompanyScope()->where('company_id', $company->id)->count();
        $empCount = Employee::withoutCompanyScope()->where('company_id', $company->id)->count();

        return [
            'state' => [
                'status' => $state->status,
                'current_step' => $state->current_step,
                'completed_steps' => $state->completed_steps ?? [],
                'deferred_steps' => $state->deferred_steps ?? [],
                'started_at' => $state->started_at?->toISOString(),
                'completed_at' => $state->completed_at?->toISOString(),
            ],
            'context' => [
                'company' => [
                    'name' => $company->name,
                    'market_key' => $company->market_key,
                    'jobdomain_key' => $company->jobdomain_key,
                    'org_profile_key' => $company->org_profile_key,
                    'org_profile_label' => OrgProfileRegistry::label($company->jobdomain_key, $company->org_profile_key),
                    'headcount' => $company->average_headcount,
                ],
                'available_profiles' => OrgProfileRegistry::forJobdomain($company->jobdomain_key),
                // Suggestion NON contraignante (jamais imposée) : le profil déjà posé, sinon à choisir.
                'suggested_profile' => $company->org_profile_key,
                'applicable_steps' => static::applicableSteps($company),
                'has_preset' => (bool) OrganizationPresetResolver::resolveFor($company),
                'existing_org' => ['departments' => $deptCount, 'job_roles' => $roleCount, 'has_org' => $deptCount > 0],
                'existing_employees' => $empCount,
                'available_roles' => CompanyRole::where('company_id', $company->id)
                    ->get(['key', 'name', 'is_administrative'])
                    ->map(fn ($r) => ['key' => $r->key, 'name' => $r->name, 'is_administrative' => (bool) $r->is_administrative])->all(),
            ],
        ];
    }

    public static function ensure(Company $company): WorkforceOnboarding
    {
        return WorkforceOnboarding::withoutCompanyScope()->firstOrCreate(
            ['company_id' => $company->id],
            ['status' => WorkforceOnboarding::STATUS_NOT_STARTED, 'version' => 1],
        );
    }

    public static function start(Company $company): WorkforceOnboarding
    {
        $state = static::ensure($company);
        if ($state->status === WorkforceOnboarding::STATUS_NOT_STARTED) {
            $state->update(['status' => WorkforceOnboarding::STATUS_IN_PROGRESS, 'started_at' => now(), 'current_step' => 'company']);
        }

        return $state;
    }

    /**
     * Marque une étape terminée — SEULEMENT si son opération métier a réellement réussi (ex. 'organization'
     * exige une organisation existante). Idempotent, verrouillé (anti-double-soumission).
     */
    public static function completeStep(Company $company, string $step): array
    {
        if (! in_array($step, static::applicableSteps($company), true)) {
            abort(422, "Step '{$step}' is not applicable.");
        }

        return DB::transaction(function () use ($company, $step) {
            $state = WorkforceOnboarding::withoutCompanyScope()->where('company_id', $company->id)->lockForUpdate()->first()
                ?? static::start($company);

            // Garde métier : 'organization' ne peut être terminée que si une organisation existe réellement.
            if ($step === 'organization') {
                $has = Department::withoutCompanyScope()->where('company_id', $company->id)->exists();
                if (! $has) {
                    abort(422, 'Organization step requires an existing organization (apply a preset or create a department first).');
                }
            }

            $completed = array_values(array_unique(array_merge($state->completed_steps ?? [], [$step])));
            $deferred = array_values(array_diff($state->deferred_steps ?? [], [$step]));
            $state->update([
                'status' => WorkforceOnboarding::STATUS_IN_PROGRESS,
                'completed_steps' => $completed,
                'deferred_steps' => $deferred,
                'current_step' => static::nextStep($company, $step),
            ]);

            return static::stateWithContext($company);
        });
    }

    public static function deferStep(Company $company, string $step): array
    {
        $state = static::ensure($company);
        $deferred = array_values(array_unique(array_merge($state->deferred_steps ?? [], [$step])));
        $state->update(['deferred_steps' => $deferred, 'current_step' => static::nextStep($company, $step), 'status' => WorkforceOnboarding::STATUS_IN_PROGRESS]);

        return static::stateWithContext($company);
    }

    public static function finish(Company $company): array
    {
        $state = static::ensure($company);
        $state->update(['status' => WorkforceOnboarding::STATUS_COMPLETED, 'completed_at' => now(), 'current_step' => 'done']);
        // Marque aussi l'onboarding général comme traité (ne jamais réafficher un assistant bloquant).
        if (! $company->onboarding_dismissed_at) {
            $company->update(['onboarding_dismissed_at' => now()]);
        }

        return static::stateWithContext($company);
    }

    private static function nextStep(Company $company, string $current): ?string
    {
        $steps = static::applicableSteps($company);
        $i = array_search($current, $steps, true);

        return ($i !== false && isset($steps[$i + 1])) ? $steps[$i + 1] : null;
    }
}
