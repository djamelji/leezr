<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Modules\Workforce\Services\ScheduleTemplateCompositionValidator;
use App\Modules\Workforce\UseCases\BulkApplyTemplateUseCase;
use App\Modules\Workforce\UseCases\CreateScheduleTemplateUseCase;
use App\Modules\Workforce\UseCases\CreateShiftUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Refonte Smart Planning — M1 (ADR-724) : modèles de shift enrichis + matérialisation des activités.
 *
 * Vérifie : contrat horaire ancien (compat ascendante), modèle enrichi (snapshot), 3 activités, pause
 * positionnée, shift nocturne, activité traversant minuit, chevauchements, données invalides, rollback
 * atomique, modification ultérieure sans effet rétroactif (brouillon ET publié), préservation du snapshot,
 * isolation tenant, TimeEntry inchangé, manager scope (bulk), matérialisation via bulk-template.
 */
class WorkforceScheduleTemplateCompositionM1Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        \App\Company\RBAC\CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'M1', 'slug' => 'm1-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#3b82f6', 'enabled' => true]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emp(?int $companyId = null): Employee
    {
        $cid = $companyId ?? $this->company->id;
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $cid, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $cid, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);

        return $e->fresh();
    }

    /** Crée un modèle via le UseCase, avec composition normalisée (ou null = horaire pur). */
    private function template(string $start, string $end, ?array $composition = null, ?int $companyId = null): ScheduleTemplate
    {
        $cid = $companyId ?? $this->company->id;
        $norm = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize($composition, $start, $end);

        return app(CreateScheduleTemplateUseCase::class)->execute(
            companyId: $cid, name: 'TPL '.uniqid(), startTime: $start, endTime: $end,
            coverageCategoryId: $this->route->id, composition: $norm,
        );
    }

    private function apply(ScheduleTemplate $tpl, int $empId, string $date): Shift
    {
        $start = Carbon::parse($date.' '.substr($tpl->start_time, 0, 5));
        $end = Carbon::parse($date.' '.substr($tpl->end_time, 0, 5));
        if ($end->lte($start)) {
            $end->addDay();
        }

        return app(CreateShiftUseCase::class)->execute(
            companyId: $tpl->company_id, employeeId: $empId, date: $date,
            startAt: $start->toDateTimeString(), endAt: $end->toDateTimeString(), timezone: 'Europe/Paris',
            scheduleTemplateId: $tpl->id, materializeComposition: true,
        );
    }

    private function threeActivityComposition(): array
    {
        return [
            'schema_version' => 1,
            'activities' => [
                ['label' => 'Brief', 'coverage_category_id' => $this->route->id, 'offset_minutes' => 0, 'duration_minutes' => 30],
                ['label' => 'Tournée A', 'coverage_category_id' => $this->route->id, 'offset_minutes' => 30, 'duration_minutes' => 180],
                ['label' => 'Tournée B', 'coverage_category_id' => $this->route->id, 'offset_minutes' => 270, 'duration_minutes' => 210],
            ],
            'breaks' => [['offset_minutes' => 210, 'duration_minutes' => 60]],
        ];
    }

    // --- 1. Compat ascendante : modèle horaire pur ---
    public function test_legacy_template_without_composition_keeps_old_snapshot_and_zero_activities(): void
    {
        $tpl = $this->template('09:00', '17:00', null);
        $snap = $tpl->toSnapshot();
        $this->assertArrayNotHasKey('schema_version', $snap);
        $this->assertArrayNotHasKey('activities', $snap);
        $this->assertArrayNotHasKey('break_windows', $snap);

        $shift = $this->apply($tpl, $this->emp()->id, '2026-11-03');
        $this->assertSame(0, ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->count());
    }

    // --- 2. Modèle enrichi : snapshot complet ---
    public function test_enriched_template_snapshot_has_schema_activities_and_break_windows(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $snap = $tpl->toSnapshot();
        $this->assertSame(1, $snap['schema_version']);
        $this->assertCount(3, $snap['activities']);
        $this->assertSame([['start' => '12:30', 'end' => '13:30']], $snap['break_windows']);
    }

    // --- 3. Trois activités matérialisées ---
    public function test_apply_materializes_three_activities(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $emp = $this->emp();
        $shift = $this->apply($tpl, $emp->id, '2026-11-03');

        $acts = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->orderBy('start_at')->get();
        $this->assertCount(3, $acts);
        $this->assertSame($emp->id, $acts[0]->employee_id, 'employee_id dérivé du shift.');
        $this->assertSame('template', $acts[0]->source);
        $this->assertSame('2026-11-03 09:00:00', $acts[0]->start_at->toDateTimeString());
        $this->assertSame('2026-11-03 09:30:00', $acts[0]->end_at->toDateTimeString());
        $this->assertSame('2026-11-03 13:30:00', $acts[2]->start_at->toDateTimeString());
        $this->assertSame('2026-11-03 17:00:00', $acts[2]->end_at->toDateTimeString());
    }

    // --- 4. Pause positionnée : activité chevauchant la pause refusée à l'authoring ---
    public function test_activity_overlapping_break_rejected_at_authoring(): void
    {
        $this->expectException(\DomainException::class);
        $this->template('09:00', '17:00', [
            'schema_version' => 1,
            'activities' => [['label' => 'X', 'offset_minutes' => 200, 'duration_minutes' => 60]], // 12:20-13:20 chevauche 12:30-13:30
            'breaks' => [['offset_minutes' => 210, 'duration_minutes' => 60]],
        ]);
    }

    // --- 5. Shift nocturne ---
    public function test_night_shift_template_applies(): void
    {
        $tpl = $this->template('22:00', '06:00', [
            'schema_version' => 1,
            'activities' => [['label' => 'Nuit', 'coverage_category_id' => $this->route->id, 'offset_minutes' => 0, 'duration_minutes' => 240]],
        ]);
        $shift = $this->apply($tpl, $this->emp()->id, '2026-11-03');
        $this->assertSame('2026-11-04 06:00:00', $shift->end_at->toDateTimeString());
        $acts = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->get();
        $this->assertCount(1, $acts);
        $this->assertSame('2026-11-03 22:00:00', $acts[0]->start_at->toDateTimeString());
    }

    // --- 6. Activité traversant minuit ---
    public function test_activity_crossing_midnight_is_materialized(): void
    {
        $tpl = $this->template('22:00', '06:00', [
            'schema_version' => 1,
            'activities' => [['label' => 'Transit', 'offset_minutes' => 60, 'duration_minutes' => 120]], // 23:00 -> 01:00
        ]);
        $shift = $this->apply($tpl, $this->emp()->id, '2026-11-03');
        $a = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->first();
        $this->assertSame('2026-11-03 23:00:00', $a->start_at->toDateTimeString());
        $this->assertSame('2026-11-04 01:00:00', $a->end_at->toDateTimeString());
    }

    // --- 7. Chevauchement entre activités exclusives ---
    public function test_overlapping_activities_rejected_at_authoring(): void
    {
        $this->expectException(\DomainException::class);
        $this->template('09:00', '17:00', [
            'schema_version' => 1,
            'activities' => [
                ['label' => 'A', 'offset_minutes' => 0, 'duration_minutes' => 120],
                ['label' => 'B', 'offset_minutes' => 60, 'duration_minutes' => 120],
            ],
        ]);
    }

    // --- 8. Données invalides ---
    public function test_invalid_compositions_are_rejected(): void
    {
        $v = app(ScheduleTemplateCompositionValidator::class);
        $cases = [
            ['schema_version' => 2, 'activities' => []],
            ['schema_version' => 1, 'activities' => [['offset_minutes' => -10, 'duration_minutes' => 30]]],
            ['schema_version' => 1, 'activities' => [['offset_minutes' => 0, 'duration_minutes' => 0]]],
            ['schema_version' => 1, 'activities' => [['offset_minutes' => 400, 'duration_minutes' => 120]]], // 400+120 > 480
            ['schema_version' => 1, 'activities' => [['offset_minutes' => 0, 'duration_minutes' => 30, 'priority' => 'urgent']]],
        ];
        foreach ($cases as $i => $c) {
            try {
                $v->validateAndNormalize($c, '09:00', '17:00');
                $this->fail("Composition invalide #$i acceptée à tort.");
            } catch (\DomainException $e) {
                $this->assertTrue(true);
            }
        }
    }

    // --- 9. Rollback atomique : une activité invalide annule tout le shift ---
    public function test_materialization_rollback_is_atomic(): void
    {
        // Composition écrite DIRECTEMENT (contourne l'authoring) avec une activité hors bornes → la
        // matérialisation (autorité ScheduledActivityValidator) lève et annule le shift complet.
        $tpl = ScheduleTemplate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'BAD '.uniqid(), 'start_time' => '09:00', 'end_time' => '12:00',
            'metadata' => ['composition' => ['schema_version' => 1, 'activities' => [
                ['id' => 'a1', 'label' => 'OK', 'offset_minutes' => 0, 'duration_minutes' => 60, 'priority' => 'normal', 'non_exclusive' => false],
                ['id' => 'a2', 'label' => 'HORS', 'offset_minutes' => 120, 'duration_minutes' => 120, 'priority' => 'normal', 'non_exclusive' => false], // 11:00-13:00 hors du shift 09-12
            ], 'breaks' => []]],
        ]);
        $emp = $this->emp();

        try {
            $this->apply($tpl, $emp->id, '2026-11-03');
            $this->fail('La matérialisation aurait dû échouer.');
        } catch (\DomainException $e) {
            // attendu
        }

        $this->assertSame(0, Shift::withoutGlobalScopes()->where('employee_id', $emp->id)->count(), 'Aucun shift incomplet conservé.');
        $this->assertSame(0, ScheduledActivity::withoutGlobalScopes()->where('employee_id', $emp->id)->count(), 'Aucune activité orpheline.');
    }

    // --- 10. Modification du modèle : aucun effet rétroactif (brouillon) ---
    public function test_modifying_template_does_not_change_existing_shift(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $shift = $this->apply($tpl, $this->emp()->id, '2026-11-03');
        $snapBefore = $shift->template_snapshot;
        $actsBefore = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->pluck('label')->sort()->values()->all();

        // Change radicalement la composition du modèle.
        $newComp = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize([
            'schema_version' => 1, 'activities' => [['label' => 'Autre', 'offset_minutes' => 0, 'duration_minutes' => 60]], 'breaks' => [],
        ], '09:00', '17:00');
        $tpl->metadata = ['composition' => $newComp];
        $tpl->save();

        $shift->refresh();
        $actsAfter = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->pluck('label')->sort()->values()->all();
        $this->assertSame($snapBefore, $shift->template_snapshot, 'Snapshot figé inchangé.');
        $this->assertSame($actsBefore, $actsAfter, 'Activités du shift existant inchangées.');
        $this->assertCount(3, $actsAfter);
    }

    // --- 11. Snapshot préservé sur le shift ---
    public function test_snapshot_composition_is_preserved_on_shift(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $shift = $this->apply($tpl, $this->emp()->id, '2026-11-03');
        $this->assertSame(1, $shift->template_snapshot['schema_version']);
        $this->assertCount(3, $shift->template_snapshot['activities']);
        $this->assertSame([['start' => '12:30', 'end' => '13:30']], $shift->template_snapshot['break_windows']);
    }

    // --- 12. Aucun effet rétroactif sur un shift PUBLIÉ ---
    public function test_no_retroactive_effect_on_published_shift(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $shift = $this->apply($tpl, $this->emp()->id, '2026-11-03');
        app(\App\Modules\Workforce\UseCases\PublishShiftsUseCase::class)->execute($this->company->id, [$shift->id]);
        $shift->refresh();
        $this->assertSame(Shift::STATUS_PUBLISHED, $shift->status);
        $snapBefore = $shift->template_snapshot;

        $tpl->metadata = ['composition' => app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize([
            'schema_version' => 1, 'activities' => [['label' => 'Z', 'offset_minutes' => 0, 'duration_minutes' => 30]], 'breaks' => [],
        ], '09:00', '17:00')];
        $tpl->save();

        $shift->refresh();
        $this->assertSame(Shift::STATUS_PUBLISHED, $shift->status, 'Shift publié reste publié.');
        $this->assertSame($snapBefore, $shift->template_snapshot, 'Snapshot publié immuable.');
        $this->assertSame(3, ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->count());
    }

    // --- 13. Isolation tenant : modèle d'une autre company inapplicable ---
    public function test_tenant_isolation_template_from_other_company_is_rejected(): void
    {
        Model::unguard();
        $other = Company::create(['name' => 'OTH', 'slug' => 'oth-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        Model::reguard();
        $foreignTpl = $this->template('09:00', '17:00', $this->threeActivityComposition(), $other->id);
        $emp = $this->emp(); // de $this->company

        $this->expectException(\DomainException::class);
        app(CreateShiftUseCase::class)->execute(
            companyId: $this->company->id, employeeId: $emp->id, date: '2026-11-03',
            startAt: '2026-11-03 09:00:00', endAt: '2026-11-03 17:00:00', timezone: 'Europe/Paris',
            scheduleTemplateId: $foreignTpl->id, materializeComposition: true,
        );
    }

    // --- 14. TimeEntry inchangé (prévisionnel ≠ pointage) ---
    public function test_timeentry_is_not_created_by_materialization(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $emp = $this->emp();
        $this->apply($tpl, $emp->id, '2026-11-03');
        $this->assertSame(0, TimeEntry::withoutGlobalScopes()->where('employee_id', $emp->id)->count());
    }

    // --- 15. Manager scope (bulk) : cellule hors périmètre bloquée ---
    public function test_bulk_apply_respects_manager_scope(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $emp = $this->emp();
        $preview = app(BulkApplyTemplateUseCase::class)->preview(
            $this->company->id, $tpl->id, [['employee_id' => $emp->id, 'date' => '2026-11-03']], visibleIds: [999999],
        );
        $this->assertSame('blocked', $preview['cells'][0]['status']);
        $this->assertSame(BulkApplyTemplateUseCase::BLOCK_OUT_OF_SCOPE, $preview['cells'][0]['reason']);
    }

    // --- 16. Bulk-template matérialise aussi les activités ---
    public function test_bulk_apply_materializes_activities(): void
    {
        $tpl = $this->template('09:00', '17:00', $this->threeActivityComposition());
        $emp = $this->emp();
        $res = app(BulkApplyTemplateUseCase::class)->commit(
            $this->company->id, $tpl->id, [['employee_id' => $emp->id, 'date' => '2026-11-03']],
            visibleIds: null, ack: null, reason: null, actorId: null,
        );
        $this->assertSame(1, $res['created_count']);
        $shiftId = $res['created'][0];
        $this->assertSame(3, ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shiftId)->count());
    }
}
