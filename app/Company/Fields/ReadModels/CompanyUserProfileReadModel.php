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
    public static function get(User $user, Company $company, ?string $roleKey = null, bool $canReadSensitive = true, ?string $category = null): array
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
            'dynamic_fields' => $resolved,
            'profile_completeness' => [
                'filled' => count($filled),
                'total' => count($required),
                'complete' => count($required) === 0 || count($filled) === count($required),
            ],
        ];
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

        // Now compute completeness for each membership in memory (0 queries)
        $result = [];

        foreach ($memberships as $membership) {
            $roleKey = $membership->companyRole?->key;
            $userId = $membership->user_id;

            // Build field list considering role overrides
            $role = $roleKey ? ($rolesByKey[$roleKey] ?? null) : null;
            $roleRequiredTags = $role?->required_tags;
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
                $mandatory = MandatoryContext::isMandatory($definition, $mandatoryContext, $roleRequiredTags);
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

                $value = $userValues->get($defId)?->value;
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
