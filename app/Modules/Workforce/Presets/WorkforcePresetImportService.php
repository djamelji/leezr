<?php

namespace App\Modules\Workforce\Presets;

use App\Core\Models\Company;
use App\Core\Workforce\ActivityPreset;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use App\Modules\Workforce\Services\ScheduleTemplateCompositionValidator;
use Illuminate\Support\Facades\DB;

/**
 * Import des PRESETS MÉTIER Workforce proposés par le JobDomain (Refonte Smart Planning — M5, ADR-728).
 *
 * Cloné sur OrganizationPresetService (ADR-636) : preview() n'écrit RIEN ; apply() est transactionnel,
 * verrouillé (anti-concurrence) et IDEMPOTENT. Idempotence par PROVENANCE (metadata.provenance.code) — un
 * objet déjà issu de ce preset est conservé tel quel (même renommé/personnalisé) ; un homonyme créé par la
 * Company (sans provenance) est signalé en conflit et JAMAIS écrasé/dédoublé. Rejouer = aucun doublon.
 *
 * Résolution de dépendances : catégories de couverture → tâches types → modèles de shift (composition) →
 * semaines types. Références par CODE résolues vers les identifiants Company. Import PARTIEL possible (les
 * dépendances des éléments choisis sont résolues). N'importe JAMAIS de salarié, ne crée NI Shift NI
 * CoverageRequirement. Les objets importés sont AUTONOMES (copie par valeur) : une modification Platform
 * ultérieure ne les réécrit pas.
 */
class WorkforcePresetImportService
{
    private function presets(Company $company): array
    {
        return $company->jobdomain->workforce_presets ?? [];
    }

    private function provKey(array $item): ?string
    {
        return $item['metadata']['provenance']['code'] ?? null;
    }

    /** Index des objets Company issus de CE preset (par code de provenance) + par nom (homonymes Company). */
    private function existingByProv($collection): array
    {
        $byCode = [];
        foreach ($collection as $e) {
            $code = $e->metadata['provenance']['code'] ?? null;
            if ($code) {
                $byCode[$code] = $e;
            }
        }

        return $byCode;
    }

    /** Preview READ-ONLY : classe chaque item (new / existing / customized / conflict). N'écrit RIEN. */
    public function preview(Company $company): array
    {
        $presets = $this->presets($company);
        if (! $presets) {
            return ['has_presets' => false];
        }
        $cid = $company->id;

        $cats = CoverageCategory::withoutGlobalScopes()->where('company_id', $cid)->get();
        $acts = ActivityPreset::withoutGlobalScopes()->where('company_id', $cid)->get();
        $tpls = ScheduleTemplate::withoutGlobalScopes()->where('company_id', $cid)->get();
        $weeks = WeekTemplate::withoutGlobalScopes()->where('company_id', $cid)->get();

        $classify = function (array $item, $collection, string $nameField) {
            $code = $item['code'];
            $byCode = $this->existingByProv($collection);
            if (isset($byCode[$code])) {
                $e = $byCode[$code];
                $cur = $e->{$nameField} ?? null;

                return mb_strtolower((string) $cur) === mb_strtolower((string) $item['name']) ? 'existing' : 'customized';
            }
            foreach ($collection as $e) {
                if (mb_strtolower((string) ($e->{$nameField} ?? '')) === mb_strtolower((string) $item['name'])) {
                    return 'conflict'; // homonyme Company
                }
            }

            return 'new';
        };

        $map = fn (array $items, $coll, string $nf) => array_map(fn ($i) => [
            'code' => $i['code'], 'name' => $i['name'], 'status' => $classify($i, $coll, $nf),
        ], $items);

        $sched = $map($presets['schedule_templates'] ?? [], $tpls, 'name');
        $week = $map($presets['week_templates'] ?? [], $weeks, 'name');
        $act = $map($presets['activity_presets'] ?? [], $acts, 'name');
        $cat = $map($presets['coverage_categories'] ?? [], $cats, 'name');
        $all = array_merge($sched, $week, $act, $cat);
        $count = fn (string $s) => count(array_filter($all, fn ($i) => $i['status'] === $s));

        return [
            'has_presets' => true,
            'jobdomain' => $company->jobdomain_key,
            'schema_version' => $presets['schema_version'] ?? 1,
            'coverage_categories' => $cat, 'activity_presets' => $act,
            'schedule_templates' => $sched, 'week_templates' => $week,
            'summary' => ['new' => $count('new'), 'existing' => $count('existing'), 'customized' => $count('customized'), 'conflicts' => $count('conflict')],
        ];
    }

    /**
     * Import IDEMPOTENT. $selected = liste de "type:code" (schedule_template / week_template / activity_preset) ;
     * null = tout. Les dépendances des éléments choisis sont résolues (catégories + tâches + shifts requis).
     */
    public function apply(Company $company, ?array $selected = null, ?int $appliedBy = null): array
    {
        $presets = $this->presets($company);
        if (! $presets) {
            return ['has_presets' => false, 'created' => [], 'skipped' => [], 'conflicts' => []];
        }

        return DB::transaction(function () use ($company, $presets, $selected) {
            Company::withoutGlobalScopes()->where('id', $company->id)->lockForUpdate()->first();
            $cid = $company->id;
            $jd = $company->jobdomain_key;
            $version = $presets['schema_version'] ?? 1;
            $sel = $selected === null ? null : array_flip($selected);
            $created = [];
            $skipped = [];
            $conflicts = [];

            $prov = fn (string $code) => ['provenance' => ['source' => 'jobdomain_preset', 'jobdomain' => $jd, 'version' => $version, 'code' => $code]];

            // Fermeture de dépendances : quels shifts/semaines/tâches importer.
            $wantWeek = fn (string $c) => $sel === null || isset($sel["week_template:$c"]);
            $neededSched = [];
            foreach ($presets['week_templates'] ?? [] as $w) {
                if ($wantWeek($w['code'])) {
                    foreach ($w['lines'] as $ln) {
                        $neededSched[$ln['schedule_template_code']] = true;
                    }
                }
            }
            $wantSched = fn (string $c) => $sel === null || isset($sel["schedule_template:$c"]) || isset($neededSched[$c]);

            // ── 1) Catégories de couverture (base, toujours résolues) ──
            $catByCode = [];
            $existingCats = CoverageCategory::withoutGlobalScopes()->where('company_id', $cid)->get();
            $existingCatByCode = $this->existingByProv($existingCats);
            $catByName = $existingCats->keyBy(fn ($c) => mb_strtolower($c->name));
            foreach ($presets['coverage_categories'] ?? [] as $c) {
                $code = $c['code'];
                if (isset($existingCatByCode[$code])) {
                    $catByCode[$code] = $existingCatByCode[$code]->id;
                    $skipped[] = "coverage_category:$code";

                    continue;
                }
                if ($catByName->has(mb_strtolower($c['name']))) {
                    $catByCode[$code] = $catByName->get(mb_strtolower($c['name']))->id; // réutilise l'homonyme Company
                    $conflicts[] = "coverage_category:$code:name_clash";

                    continue;
                }
                $cat = CoverageCategory::withoutGlobalScopes()->create([
                    'company_id' => $cid, 'code' => strtoupper($code), 'name' => $c['name'], 'color' => $c['color'] ?? null,
                    'enabled' => true, 'metadata' => $prov($code),
                ]);
                $catByCode[$code] = $cat->id;
                $created[] = "coverage_category:$code";
            }

            // ── 2) Tâches types ──
            $actByCode = [];
            $existingActs = ActivityPreset::withoutGlobalScopes()->where('company_id', $cid)->get();
            $existingActByCode = $this->existingByProv($existingActs);
            $actByName = $existingActs->keyBy(fn ($a) => mb_strtolower($a->name));
            foreach ($presets['activity_presets'] ?? [] as $a) {
                $code = $a['code'];
                if (isset($existingActByCode[$code])) {
                    $actByCode[$code] = $existingActByCode[$code];
                    $skipped[] = "activity_preset:$code";

                    continue;
                }
                if ($actByName->has(mb_strtolower($a['name']))) {
                    $actByCode[$code] = $actByName->get(mb_strtolower($a['name']));
                    $conflicts[] = "activity_preset:$code:name_clash";

                    continue;
                }
                $preset = ActivityPreset::withoutGlobalScopes()->create([
                    'company_id' => $cid, 'name' => $a['name'], 'activity_type' => $a['activity_type'] ?? null,
                    'coverage_category_id' => isset($a['coverage_category_code']) ? ($catByCode[$a['coverage_category_code']] ?? null) : null,
                    'default_duration_minutes' => $a['default_duration_minutes'] ?? null, 'priority' => $a['priority'] ?? 'normal',
                    'enabled' => true, 'metadata' => $prov($code),
                ]);
                $actByCode[$code] = $preset;
                $created[] = "activity_preset:$code";
            }

            // ── 3) Modèles de shift (composition résolue via tâches + catégories) ──
            $schedByCode = [];
            $existingSched = ScheduleTemplate::withoutGlobalScopes()->where('company_id', $cid)->get();
            $existingSchedByCode = $this->existingByProv($existingSched);
            $schedByName = $existingSched->keyBy(fn ($t) => mb_strtolower($t->name));
            foreach ($presets['schedule_templates'] ?? [] as $t) {
                $code = $t['code'];
                if (! $wantSched($code)) {
                    continue;
                }
                if (isset($existingSchedByCode[$code])) {
                    $schedByCode[$code] = $existingSchedByCode[$code]->id;
                    $skipped[] = "schedule_template:$code";

                    continue;
                }
                if ($schedByName->has(mb_strtolower($t['name']))) {
                    $schedByCode[$code] = $schedByName->get(mb_strtolower($t['name']))->id;
                    $conflicts[] = "schedule_template:$code:name_clash";

                    continue;
                }
                $composition = null;
                if (! empty($t['composition'])) {
                    $activities = array_map(function ($a) use ($actByCode, $catByCode) {
                        $src = isset($a['preset_code']) ? ($actByCode[$a['preset_code']] ?? null) : null;

                        return array_filter([
                            'preset_id' => $src?->id,
                            'label' => $a['label'] ?? $src?->name,
                            'activity_type' => $a['activity_type'] ?? $src?->activity_type,
                            'coverage_category_id' => $a['coverage_category_id'] ?? $src?->coverage_category_id,
                            'offset_minutes' => $a['offset_minutes'], 'duration_minutes' => $a['duration_minutes'],
                            'priority' => $a['priority'] ?? $src?->priority ?? 'normal',
                        ], fn ($v) => $v !== null);
                    }, $t['composition']['activities'] ?? []);
                    $composition = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize(
                        ['schema_version' => 1, 'activities' => $activities, 'breaks' => $t['composition']['breaks'] ?? []],
                        $t['start_time'], $t['end_time'],
                    );
                }
                $tpl = ScheduleTemplate::withoutGlobalScopes()->create([
                    'company_id' => $cid, 'name' => $t['name'], 'code' => $t['code'] ?? null,
                    'start_time' => $t['start_time'], 'end_time' => $t['end_time'],
                    'break_duration_minutes' => $t['break_duration_minutes'] ?? 0, 'color' => $t['color'] ?? null,
                    'coverage_category_id' => isset($t['coverage_category_code']) ? ($catByCode[$t['coverage_category_code']] ?? null) : null,
                    'enabled' => true,
                    'metadata' => $prov($code) + ($composition ? ['composition' => $composition] : []),
                ]);
                $schedByCode[$code] = $tpl->id;
                $created[] = "schedule_template:$code";
            }

            // ── 4) Semaines types (lignes résolues vers les modèles de shift importés) ──
            $existingWeeks = WeekTemplate::withoutGlobalScopes()->where('company_id', $cid)->get();
            $existingWeekByCode = $this->existingByProv($existingWeeks);
            $weekByName = $existingWeeks->keyBy(fn ($w) => mb_strtolower($w->name));
            foreach ($presets['week_templates'] ?? [] as $w) {
                $code = $w['code'];
                if (! $wantWeek($code)) {
                    continue;
                }
                if (isset($existingWeekByCode[$code])) {
                    $skipped[] = "week_template:$code";

                    continue;
                }
                if ($weekByName->has(mb_strtolower($w['name']))) {
                    $conflicts[] = "week_template:$code:name_clash";

                    continue;
                }
                $wt = WeekTemplate::withoutGlobalScopes()->create([
                    'company_id' => $cid, 'name' => $w['name'], 'strategy' => $w['strategy'] ?? 'needs',
                    'scope' => 'company', 'enabled' => true, 'metadata' => $prov($code),
                ]);
                $order = 0;
                foreach ($w['lines'] as $ln) {
                    $stId = $schedByCode[$ln['schedule_template_code']] ?? null;
                    if (! $stId) {
                        continue; // dépendance non résolue (modèle non importé) : ligne ignorée
                    }
                    WeekTemplateLine::withoutGlobalScopes()->create([
                        'company_id' => $cid, 'week_template_id' => $wt->id, 'day_index' => $ln['day_index'],
                        'schedule_template_id' => $stId, 'kind' => $ln['kind'] ?? 'needs',
                        'headcount' => ($ln['kind'] ?? 'needs') === 'needs' ? (int) ($ln['headcount'] ?? 1) : null,
                        'sort_order' => $order++,
                    ]);
                }
                $created[] = "week_template:$code";
            }

            return ['has_presets' => true, 'created' => $created, 'skipped' => $skipped, 'conflicts' => $conflicts];
        });
    }
}
