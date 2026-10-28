<?php

namespace App\Modules\Workforce\Organization;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;

/**
 * ADR-638 (W-E2) — validation SERVEUR de la structure d'un preset (jamais remplacée par le front).
 * Refuse : parent inexistant, référence circulaire, clés dupliquées, poste rattaché à un département
 * inexistant, profil non autorisé pour le jobdomain, jobdomain/market inexistant.
 *
 * @return array liste des erreurs (vide = valide)
 */
class OrganizationPresetStructureValidator
{
    public static function validate(array $attrs): array
    {
        $errors = [];
        $jobdomain = $attrs['jobdomain_key'] ?? null;
        $market = $attrs['market_key'] ?? null;
        $profile = $attrs['org_profile_key'] ?? null;
        $structure = $attrs['structure'] ?? [];

        if (! $jobdomain || ! Jobdomain::where('key', $jobdomain)->exists()) {
            $errors[] = 'jobdomain_unknown';
        }
        if ($market !== null && $market !== '' && ! Market::where('key', $market)->exists()) {
            $errors[] = 'market_unknown';
        }
        if ($profile !== null && $profile !== '' && ! OrgProfileRegistry::isValid($jobdomain, $profile)) {
            $errors[] = 'profile_invalid_for_jobdomain';
        }

        $depts = $structure['departments'] ?? [];
        $roles = $structure['job_roles'] ?? [];

        // Clés de départements uniques + parent existant + pas de cycle.
        $deptKeys = array_column($depts, 'key');
        if (count($deptKeys) !== count(array_unique($deptKeys))) {
            $errors[] = 'duplicate_department_key';
        }
        $deptSet = array_flip($deptKeys);
        $parentOf = [];
        foreach ($depts as $d) {
            if (empty($d['key']) || empty($d['name'])) {
                $errors[] = 'department_missing_key_or_name';

                continue;
            }
            $p = $d['parent_key'] ?? null;
            if ($p !== null && $p !== '' && ! isset($deptSet[$p])) {
                $errors[] = "department_parent_unknown:{$d['key']}";
            }
            $parentOf[$d['key']] = ($p !== '' ? $p : null);
        }
        if (static::hasCycle($parentOf)) {
            $errors[] = 'department_cycle';
        }

        // Postes : clés uniques + département référencé existant.
        $roleKeys = array_column($roles, 'key');
        if (count($roleKeys) !== count(array_unique($roleKeys))) {
            $errors[] = 'duplicate_job_role_key';
        }
        foreach ($roles as $r) {
            if (empty($r['key']) || empty($r['title'])) {
                $errors[] = 'job_role_missing_key_or_title';

                continue;
            }
            $dk = $r['department_key'] ?? null;
            if ($dk !== null && $dk !== '' && ! isset($deptSet[$dk])) {
                $errors[] = "job_role_department_unknown:{$r['key']}";
            }
        }

        return array_values(array_unique($errors));
    }

    private static function hasCycle(array $parentOf): bool
    {
        foreach (array_keys($parentOf) as $start) {
            $seen = [];
            $cur = $start;
            while ($cur !== null && isset($parentOf[$cur])) {
                if (isset($seen[$cur])) {
                    return true;
                }
                $seen[$cur] = true;
                $cur = $parentOf[$cur];
            }
        }

        return false;
    }
}
