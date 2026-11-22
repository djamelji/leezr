<?php

namespace App\Company\Fields\ReadModels;

use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldActivation;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldResolverService;
use App\Core\Fields\FieldValue;
use App\Core\Fields\MandatoryContext;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Support\Collection;

class CompanyUserProfileReadModel
{
    /**
     * ADR-787 — what a DIRECTORY reader (members.view without members.manage) sees of a colleague :
     * professional details only. Birth data, private addresses, emergency contacts, HR and licence
     * fields belong to the HR record (ADR-754 : members.view is the access facet, without HR data).
     */
    public const DIRECTORY_FIELDS = ['job_title', 'phone'];

    public static function forViewer(array $resolved, User $viewer, Company $company): array
    {
        // One query, same rule as User::hasCompanyPermission : owner, or active membership whose role
        // grants members.manage.
        $manages = \App\Core\Models\Membership::where('user_id', $viewer->id)->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('role', 'owner')->orWhere(fn ($q2) => $q2->whereNull('suspended_at')
                ->whereHas('companyRole.permissions', fn ($p) => $p->where('key', 'members.manage'))))
            ->exists();

        return $manages
            ? $resolved
            : array_values(array_filter($resolved, fn ($f) => in_array($f['code'], self::DIRECTORY_FIELDS, true)));
    }

    public static function get(User $user, Company $company, ?string $roleKey = null, bool $canReadSensitive = true, ?string $category = null, ?User $viewer = null): array
    {
        $resolved = FieldResolverService::resolve(
            model: $user,
            scope: FieldDefinition::SCOPE_COMPANY_USER,
            companyId: $company->id,
            roleKey: $roleKey,
            canReadSensitive: $canReadSensitive,
            marketKey: $company->market_key,
            category: $category,
            locale: FieldResolverService::requestLocale(),
        );

        $resolved = self::canonical($resolved, $user, $company, $canReadSensitive);
        $required = array_filter($resolved, fn ($f) => $f['required']);
        $filled = array_filter($required, fn ($f) => $f['value'] !== null && $f['value'] !== '');

        return [
            'base_fields' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->display_name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'status' => $user->status,
            ],
            'dynamic_fields' => $viewer ? self::forViewer($resolved, $viewer, $company) : $resolved,
            'profile_completeness' => [
                'filled' => count($filled),
                'total' => count($required),
                'complete' => count($required) === 0 || count($filled) === count($required),
            ],
        ];
    }

    /**
     * ADR-795.1 — the values shown are the CANONICAL ones (same resolution as the person file) :
     * employment data from this company's HR record (legacy account value flagged), personal data from
     * the account only if disclosed to this company (the person always sees their own).
     */
    private static function canonical(array $resolved, User $user, Company $company, bool $canReadSensitive): array
    {
        $self = (int) auth()->id() === (int) $user->id;
        $cells = (new \App\Core\Fields\EmployeeFieldStore($company))->cellsFor(null, $user, $self ? $user : null);

        return array_map(function ($f) use ($cells, $canReadSensitive) {
            $cell = $cells[$f['code']] ?? null;
            if (! $cell) {
                return $f;
            }
            $value = $cell['value'];
            if (! empty($f['sensitive']) && ! $canReadSensitive && $value !== null) {
                $str = (string) $value;
                $value = mb_strlen($str) <= 4 ? '****' : str_repeat('*', mb_strlen($str) - 4).mb_substr($str, -4);
                $f['masked'] = true;
            }

            return array_merge($f, ['value' => $value, 'source' => $cell['source'], 'status' => $cell['status'], 'not_disclosed' => $cell['not_disclosed'], 'owner' => $cell['owner']]);
        }, $resolved);
    }

    /**
     * ADR-168b: Bulk compute profile completeness for a collection of memberships.
     *
     * Exactly 5 queries (see plan), no N+1.
     *
     * @return array<int, array{filled: int, total: int, complete: bool}> Keyed by membership ID
     */
    public static function bulkCompleteness(Company $company, Collection $memberships): array
    {
        if ($memberships->isEmpty()) {
            return [];
        }

        // Q1: All definitions for company_user scope (platform + company custom)
        $definitions = FieldDefinition::where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->get()
            ->keyBy('id');

        if ($definitions->isEmpty()) {
            return $memberships->mapWithKeys(fn ($m) => [
                $m->id => ['filled' => 0, 'total' => 0, 'complete' => true],
            ])->all();
        }

        // Q2: All enabled activations for this company
        $activations = FieldActivation::whereIn('field_definition_id', $definitions->keys())
            ->where('company_id', $company->id)
            ->where('enabled', true)
            ->get()
            ->keyBy('field_definition_id');

        if ($activations->isEmpty()) {
            return $memberships->mapWithKeys(fn ($m) => [
                $m->id => ['filled' => 0, 'total' => 0, 'complete' => true],
            ])->all();
        }

        // Q3: MandatoryContext (cached, uses company_modules)
        $mandatoryContext = MandatoryContext::load($company->id);

        // Q4: Roles with field_config for override layer
        $roleKeys = $memberships->map(fn ($m) => $m->companyRole?->key)->filter()->unique()->values();
        $rolesByKey = [];
        if ($roleKeys->isNotEmpty()) {
            $rolesByKey = CompanyRole::where('company_id', $company->id)
                ->whereIn('key', $roleKeys)
                ->get()
                ->keyBy('key');
        }

        // Q5: All field values for all member user IDs in batch
        $userIds = $memberships->pluck('user_id')->unique()->values();
        $activeDefIds = $activations->keys();

        $allValues = FieldValue::where('model_type', 'user')
            ->whereIn('model_id', $userIds)
            ->whereIn('field_definition_id', $activeDefIds)
            ->get()
            ->groupBy('model_id');

        // ADR-795.1 (Q6) : données d'emploi lues sur la fiche RH de CETTE société quand elle les porte.
        $employeeByUser = \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('user_id', $userIds)->pluck('id', 'user_id');
        $employeeValues = $employeeByUser->isEmpty() ? collect() : FieldValue::where('model_type', 'employee')->whereIn('model_id', $employeeByUser->values())
            ->whereIn('field_definition_id', $activeDefIds)->get()->groupBy('model_id');
        $membershipValues = FieldValue::where('model_type', 'membership')->whereIn('model_id', $memberships->pluck('id'))
            ->whereIn('field_definition_id', $activeDefIds)->get()->groupBy('model_id');

        // Now compute completeness for each membership in memory (0 queries)
        $result = [];

        foreach ($memberships as $membership) {
            $roleKey = $membership->companyRole?->key;
            $userId = $membership->user_id;

            // Build field list considering role overrides
            $role = $roleKey ? ($rolesByKey[$roleKey] ?? null) : null;
            $roleRequiredTags = $role?->required_tags;
            $functionTags = ! empty($mandatoryContext['function_scoped']) ? \App\Core\Fields\FunctionActivity::tagsForUser($company->id, (int) $userId, $roleRequiredTags) : null; // ADR-861
            $configByCode = ($role && $role->field_config !== null)
                ? collect($role->field_config)->keyBy('code')
                : null;

            $requiredCount = 0;
            $filledCount = 0;
            $userValues = $allValues->get($userId, collect())->keyBy('field_definition_id');

            foreach ($activations as $defId => $activation) {
                $definition = $definitions->get($defId);
                if (!$definition) {
                    continue;
                }

                // Apply role field_config visibility
                if ($configByCode) {
                    $config = $configByCode->get($definition->code);
                    if ($config && ($config['visible'] ?? true) === false) {
                        continue;
                    }
                }

                // Compute required
                $mandatory = MandatoryContext::isMandatory($definition, $mandatoryContext, $roleRequiredTags, $functionTags);
                $isRequired = $activation->required_override
                    || ($definition->validation_rules['required'] ?? false)
                    || $mandatory;

                // Apply role field_config required override
                // ADR-169: mandatory fields cannot be downgraded by role config
                if ($configByCode) {
                    $config = $configByCode->get($definition->code);
                    if ($config && isset($config['required'])) {
                        $isRequired = $mandatory || $config['required'];
                    }
                }

                if (!$isRequired) {
                    continue;
                }

                $requiredCount++;

                $value = \App\Core\Fields\FieldOwnership::of($definition) === \App\Core\Fields\FieldOwnership::EMPLOYMENT
                    ? ($employeeValues->get($employeeByUser[$userId] ?? 0, collect())->firstWhere('field_definition_id', $defId)?->value
                        ?? $membershipValues->get($membership->id, collect())->firstWhere('field_definition_id', $defId)?->value
                        ?? $userValues->get($defId)?->value)
                    : $userValues->get($defId)?->value;
                if ($value !== null && $value !== '') {
                    $filledCount++;
                }
            }

            $result[$membership->id] = [
                'filled' => $filledCount,
                'total' => $requiredCount,
                'complete' => $requiredCount === 0 || $filledCount === $requiredCount,
            ];
        }

        return $result;
    }

    /**
     * ADR-168b: Count incomplete profiles for a company.
     */
    public static function incompleteCount(Company $company): int
    {
        $memberships = $company->memberships()
            ->with('companyRole:id,key,name')
            ->get();

        $completeness = static::bulkCompleteness($company, $memberships);

        return collect($completeness)->filter(fn ($c) => !$c['complete'])->count();
    }
}
