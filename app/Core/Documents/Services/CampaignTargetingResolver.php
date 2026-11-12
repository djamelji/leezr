<?php

namespace App\Core\Documents\Services;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Modules\Core\People\PersonRef;
use Illuminate\Support\Collection;

/**
 * ADR-761 (DMS M4) — résout un ciblage de campagne en population DÉDUPLIQUÉE (sujets), à partir
 * des données Company/Workforce existantes. Modes : all | department | employees | persons | single.
 * Les Employees sans compte sont éligibles ; une personne appartenant à plusieurs critères n'est
 * comptée qu'une fois (déduplication par sujet, assurée par PersonPopulationResolver).
 *
 * @return Collection<int, array>  personnes (voir PersonPopulationResolver)
 */
class CampaignTargetingResolver
{
    public static function resolve(Company $company, array $targeting): Collection
    {
        $population = PersonPopulationResolver::forCompany($company, (bool) ($targeting['include_terminated'] ?? false));
        $mode = $targeting['mode'] ?? 'all';

        $selected = match ($mode) {
            'all' => $population,
            'department' => $population->whereIn('department_id', array_map('intval', $targeting['department_ids'] ?? []))->values(),
            'employees' => $population->whereIn('employee_id', array_map('intval', $targeting['employee_ids'] ?? []))->values(),
            'persons', 'single' => self::byPersonRefs($population, $targeting),
            default => collect(),
        };

        // Filtre métier optionnel : statut d'emploi (ex. actifs seulement).
        if (! empty($targeting['filters']['status'])) {
            $statuses = (array) $targeting['filters']['status'];
            $selected = $selected->filter(fn ($p) => $p['status'] === null || in_array($p['status'], $statuses, true))->values();
        }

        return $selected->values();
    }

    private static function byPersonRefs(Collection $population, array $targeting): Collection
    {
        $refs = $targeting['person_refs'] ?? (isset($targeting['person_ref']) ? [$targeting['person_ref']] : []);
        $parsed = collect($refs)->map(fn ($r) => PersonRef::parse($r))->filter();

        if ($parsed->isEmpty()) {
            return collect();
        }

        return $population->filter(function ($p) use ($parsed) {
            foreach ($parsed as $ref) {
                if ($ref->isUser() && $p['user_id'] === $ref->id) {
                    return true;
                }
                // Un personRef employee cible la personne même si elle a un compte (sujet canonique).
                if ($ref->isEmployee() && $p['employee_id'] === $ref->id) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** Départements disponibles pour le ciblage (libellés). */
    public static function departments(Company $company): Collection
    {
        return \App\Core\Workforce\Department::where('company_id', $company->id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
