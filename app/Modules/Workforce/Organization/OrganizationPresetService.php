<?php

namespace App\Modules\Workforce\Organization;

use App\Core\Models\Company;
use App\Core\Workforce\Department;
use App\Core\Workforce\JobRole;
use Illuminate\Support\Facades\DB;

/**
 * ADR-636 (W-E) — aperçu et application NON DESTRUCTIVE d'un preset organisationnel.
 *
 * Invariants absolus :
 *  - `preview()` n'écrit RIEN.
 *  - `apply()` est transactionnel, verrouillé (anti-concurrence) et IDEMPOTENT : ne crée que ce qui
 *    manque, ne modifie/supprime JAMAIS une entité existante, préserve les renommages et les entités
 *    créées par la Company. Rejouer = aucun doublon.
 *  - Matching d'idempotence : `preset_item_key`. Un département/poste portant déjà ce preset_item_key
 *    est conservé tel quel (même renommé). Un homonyme créé par la Company (source null) = conflit
 *    signalé, jamais écrasé ni dédoublé.
 */
class OrganizationPresetService
{
    public static function preview(Company $company): array
    {
        $preset = OrganizationPresetResolver::resolveFor($company);
        if (! $preset) {
            return ['has_preset' => false, 'org_profile_key' => $company->org_profile_key];
        }

        $existingDeptByKey = Department::withoutCompanyScope()->where('company_id', $company->id)
            ->whereNotNull('preset_item_key')->get()->keyBy('preset_item_key');
        $existingDeptByName = Department::withoutCompanyScope()->where('company_id', $company->id)
            ->get()->keyBy(fn ($d) => mb_strtolower($d->name));
        $existingRoleByKey = JobRole::withoutCompanyScope()->where('company_id', $company->id)
            ->whereNotNull('preset_item_key')->get()->keyBy('preset_item_key');
        $existingRoleByTitle = JobRole::withoutCompanyScope()->where('company_id', $company->id)
            ->get()->keyBy(fn ($r) => mb_strtolower($r->title));

        $classify = function (string $key, string $label, $byKey, $byLabel) {
            if ($byKey->has($key)) {
                $e = $byKey->get($key);
                $cur = $e->name ?? $e->title;

                return mb_strtolower($cur) === mb_strtolower($label) ? 'existing' : 'customized';
            }
            if ($byLabel->has(mb_strtolower($label))) {
                return 'conflict'; // homonyme Company → ne pas dédoubler
            }

            return 'new';
        };

        $departments = array_map(fn ($d) => [
            'key' => $d['key'], 'name' => $d['name'], 'parent_key' => $d['parent_key'] ?? null,
            'status' => $classify($d['key'], $d['name'], $existingDeptByKey, $existingDeptByName),
        ], $preset->departments());

        $jobRoles = array_map(fn ($r) => [
            'key' => $r['key'], 'title' => $r['title'], 'department_key' => $r['department_key'] ?? null,
            'status' => $classify($r['key'], $r['title'], $existingRoleByKey, $existingRoleByTitle),
        ], $preset->jobRoles());

        $count = fn (array $items, string $s) => count(array_filter($items, fn ($i) => $i['status'] === $s));

        return [
            'has_preset' => true,
            'preset_key' => $preset->key,
            'preset_version' => $preset->version,
            'preset_label' => $preset->label,
            'org_profile_key' => $company->org_profile_key,
            'departments' => $departments,
            'job_roles' => $jobRoles,
            'summary' => [
                'new' => $count($departments, 'new') + $count($jobRoles, 'new'),
                'existing' => $count($departments, 'existing') + $count($jobRoles, 'existing'),
                'customized' => $count($departments, 'customized') + $count($jobRoles, 'customized'),
                'conflicts' => $count($departments, 'conflict') + $count($jobRoles, 'conflict'),
            ],
        ];
    }

    public static function apply(Company $company, ?int $appliedBy = null): array
    {
        return DB::transaction(function () use ($company, $appliedBy) {
            // Verrou anti-concurrence : sérialise deux applications simultanées sur la même Company.
            Company::withoutGlobalScopes()->where('id', $company->id)->lockForUpdate()->first();

            $preset = OrganizationPresetResolver::resolveFor($company);
            if (! $preset) {
                return ['has_preset' => false, 'created' => [], 'skipped' => [], 'conflicts' => []];
            }

            $created = [];
            $skipped = [];
            $conflicts = [];

            // ── Départements (parents avant enfants : ordre du preset) ──
            $deptIdByKey = []; // preset_item_key → department id (existant ou créé)
            foreach (Department::withoutCompanyScope()->where('company_id', $company->id)->whereNotNull('preset_item_key')->get() as $d) {
                $deptIdByKey[$d->preset_item_key] = $d->id;
            }
            $deptNames = Department::withoutCompanyScope()->where('company_id', $company->id)->get()
                ->keyBy(fn ($d) => mb_strtolower($d->name));

            foreach ($preset->departments() as $d) {
                $key = $d['key'];
                if (isset($deptIdByKey[$key])) {
                    $skipped[] = "department:{$key}"; // déjà issu du preset → idempotent (préserve renommage)

                    continue;
                }
                if ($deptNames->has(mb_strtolower($d['name']))) {
                    $conflicts[] = "department:{$key}:name_clash"; // homonyme Company → ne pas dédoubler

                    continue;
                }
                $parentId = isset($d['parent_key']) && $d['parent_key'] ? ($deptIdByKey[$d['parent_key']] ?? null) : null;
                $dept = Department::withoutCompanyScope()->create([
                    'company_id' => $company->id, 'name' => $d['name'], 'parent_id' => $parentId,
                    'sort_order' => $d['sort_order'] ?? 0, 'source' => 'preset', 'preset_item_key' => $key,
                ]);
                $deptIdByKey[$key] = $dept->id;
                $created[] = "department:{$key}";
            }

            // ── Postes / fonctions ──
            $roleKeys = JobRole::withoutCompanyScope()->where('company_id', $company->id)->whereNotNull('preset_item_key')->pluck('preset_item_key')->flip();
            $roleTitles = JobRole::withoutCompanyScope()->where('company_id', $company->id)->get()->keyBy(fn ($r) => mb_strtolower($r->title));

            foreach ($preset->jobRoles() as $r) {
                $key = $r['key'];
                if ($roleKeys->has($key)) {
                    $skipped[] = "job_role:{$key}";

                    continue;
                }
                if ($roleTitles->has(mb_strtolower($r['title']))) {
                    $conflicts[] = "job_role:{$key}:title_clash";

                    continue;
                }
                $deptId = isset($r['department_key']) && $r['department_key'] ? ($deptIdByKey[$r['department_key']] ?? null) : null;
                JobRole::withoutCompanyScope()->create([
                    'company_id' => $company->id, 'department_id' => $deptId, 'title' => $r['title'],
                    'level' => $r['level'] ?? null, 'source' => 'preset', 'preset_item_key' => $key,
                ]);
                $created[] = "job_role:{$key}";
            }

            $summary = ['has_preset' => true, 'preset_key' => $preset->key, 'preset_version' => $preset->version,
                'created' => $created, 'skipped' => $skipped, 'conflicts' => $conflicts];

            OrganizationPresetApplication::create([
                'company_id' => $company->id, 'preset_key' => $preset->key, 'preset_version' => $preset->version,
                'applied_by' => $appliedBy, 'summary' => $summary,
            ]);

            return $summary;
        });
    }
}
