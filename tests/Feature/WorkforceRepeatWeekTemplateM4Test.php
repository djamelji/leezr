<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use App\Modules\Workforce\Services\ScheduleTemplateCompositionValidator;
use App\Modules\Workforce\UseCases\ApplyWeekTemplateUseCase;
use App\Modules\Workforce\UseCases\CreateScheduleTemplateUseCase;
use App\Modules\Workforce\UseCases\PlanningUndoRedoUseCase;
use App\Modules\Workforce\UseCases\PublishShiftsUseCase;
use App\Modules\Workforce\UseCases\RepeatWeekTemplateUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Refonte Smart Planning — M4 (ADR-727) : répétition multi-semaines (cumulative, atomique, réversible).
 */
class WorkforceRepeatWeekTemplateM4Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;
    private \App\Core\Workforce\LeaveType $leaveType;
    private int $tplDay;
    private string $first = '2026-11-18';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 08:00:00');
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'M4', 'slug' => 'm4-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#3b82f6', 'enabled' => true]);
        $this->leaveType = \App\Core\Workforce\LeaveType::create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'CP', 'enabled' => true]);
        Model::reguard();
        $this->tplDay = $this->tpl('09:00', '17:00', true)->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tpl(string $s, string $e, bool $acts = false): ScheduleTemplate
    {
        $comp = $acts ? app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize([
            'schema_version' => 1,
            'activities' => [['label' => 'Brief', 'coverage_category_id' => $this->route->id, 'offset_minutes' => 0, 'duration_minutes' => 30]],
            'breaks' => [['offset_minutes' => 210, 'duration_minutes' => 60]],
        ], $s, $e) : null;

        return app(CreateScheduleTemplateUseCase::class)->execute(
            companyId: $this->company->id, name: 'TPL '.uniqid(), startTime: $s, endTime: $e, coverageCategoryId: $this->route->id, composition: $comp,
        );
    }

    private function emp(array $contract = []): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(array_merge(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35], $contract));

        return $e->fresh();
    }

    private function week(string $strategy, array $lines): WeekTemplate
    {
        $wt = WeekTemplate::create(['company_id' => $this->company->id, 'name' => 'WT '.uniqid(), 'strategy' => $strategy, 'scope' => 'company', 'enabled' => true]);
        $o = 0;
        foreach ($lines as $ln) {
            WeekTemplateLine::create(array_merge(['company_id' => $this->company->id, 'week_template_id' => $wt->id, 'sort_order' => $o++], $ln));
        }

        return $wt->fresh();
    }

    private function r(): RepeatWeekTemplateUseCase
    {
        return app(RepeatWeekTemplateUseCase::class);
    }

    private function user(array $perms, ?int $cid = null): User
    {
        $cid ??= $this->company->id;
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $cid, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $cid, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    // --- 1. Horizons (weekDates) ---
    public function test_horizons_and_eom_and_cross_year(): void
    {
        $c = $this->company;
        $this->assertCount(1, $this->r()->weekDates($c, $this->first, '1'));
        $this->assertCount(2, $this->r()->weekDates($c, $this->first, '2'));
        $this->assertCount(4, $this->r()->weekDates($c, $this->first, '4'));
        $this->assertCount(8, $this->r()->weekDates($c, $this->first, '8'));
        // Franchissement décembre→janvier : 4 semaines depuis le 28/12/2026.
        $x = $this->r()->weekDates($c, '2026-12-28', '4');
        $this->assertCount(4, $x);
        $this->assertSame(2027, $x[3]->year);
        // eom depuis le 18/11 : semaines commençant au plus tard le 30/11 (nov).
        $eom = $this->r()->weekDates($c, '2026-11-18', 'eom');
        foreach ($eom as $d) {
            $this->assertSame(11, $d->month);
        }
    }

    // --- 2. Premier jour Company = dimanche ---
    public function test_company_week_start_sunday(): void
    {
        $this->company->update(['week_start_day' => 0]);
        $d = $this->r()->weekDates($this->company->fresh(), $this->first, '2');
        $this->assertSame(0, $d[0]->dayOfWeek);
        $this->assertSame(0, $d[1]->dayOfWeek);
    }

    // --- 3. Répétition nominative 4 semaines → 4 shifts sur 4 semaines distinctes + activités ---
    public function test_repeat_nominative_4_weeks(): void
    {
        $e1 = $this->emp();
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $res = $this->r()->commit($this->company->id, $wt->id, $this->first, '4', [], null, null, null, 1);
        $this->assertSame(4, $res['created_count']);
        $this->assertCount(4, $res['weeks']);
        $dates = Shift::withoutGlobalScopes()->whereIn('id', $res['created'])->pluck('date')->map->toDateString()->sort()->values()->all();
        $this->assertCount(4, array_unique($dates));
        // activités matérialisées sur chaque occurrence
        $this->assertSame(4, ScheduledActivity::withoutGlobalScopes()->whereIn('shift_id', $res['created'])->count());
    }

    // --- 4. Besoins : non pourvu conservé sur chaque semaine ---
    public function test_repeat_needs_unfilled(): void
    {
        $this->emp();
        $wt = $this->week('needs', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'needs', 'headcount' => 3]]);
        $res = $this->r()->commit($this->company->id, $wt->id, $this->first, '2', [], null, null, null, 1);
        $this->assertSame(0, $res['created_count']);
        $this->assertSame(6, $res['unfilled']); // 3 × 2 semaines
    }

    // --- 5. Contrat expirant en S3 ---
    public function test_contract_expiring_mid_horizon(): void
    {
        $ws0 = $this->r()->weekDates($this->company, $this->first, '4')[0];
        $e1 = $this->emp(['contract_type' => 'cdd', 'end_date' => $ws0->copy()->addDays(10)->toDateString()]);
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $prev = $this->r()->preview($this->company->id, $wt->id, $this->first, '4', [], null, 1);
        $this->assertSame('applicable', $prev['weeks'][0]['nominative'][0]['status']);
        $this->assertSame('applicable', $prev['weeks'][1]['nominative'][0]['status']);
        $this->assertSame('blocked', $prev['weeks'][2]['nominative'][0]['status']);
        $this->assertSame(ApplyWeekTemplateUseCase::BLOCK_CONTRACT_EXPIRED, $prev['weeks'][2]['nominative'][0]['reason']);
        $res = $this->r()->commit($this->company->id, $wt->id, $this->first, '4', [], null, null, null, 1);
        $this->assertSame(2, $res['created_count']); // S1 + S2 seulement
    }

    // --- 6. Absence confirmée en S2, demande en attente en S4 ---
    public function test_absence_s2_pending_s4(): void
    {
        $e1 = $this->emp();
        $w = $this->r()->weekDates($this->company, $this->first, '4');
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e1->id, 'status' => LeaveRequest::STATUS_APPROVED, 'date_from' => $w[1]->toDateString(), 'date_to' => $w[1]->toDateString(), 'leave_type_id' => $this->leaveType->id, 'days_count_hundredths' => 100]);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $e1->id, 'status' => LeaveRequest::STATUS_PENDING, 'date_from' => $w[3]->toDateString(), 'date_to' => $w[3]->toDateString(), 'leave_type_id' => $this->leaveType->id, 'days_count_hundredths' => 100]);
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $prev = $this->r()->preview($this->company->id, $wt->id, $this->first, '4', [], null, 1);
        $this->assertSame('blocked', $prev['weeks'][1]['nominative'][0]['status']);
        $this->assertSame(ApplyWeekTemplateUseCase::BLOCK_ABSENT, $prev['weeks'][1]['nominative'][0]['reason']);
        $this->assertSame('applicable', $prev['weeks'][3]['nominative'][0]['status']);
        $this->assertSame('pending_leave', $prev['weeks'][3]['nominative'][0]['warning']);
    }

    // --- 7. Conflit CUMULATIF inter-semaines (shift nocturne de S1 débordant sur lundi de S2) ---
    public function test_cumulative_inter_week_overlap(): void
    {
        $e1 = $this->emp();
        $night = $this->tpl('20:00', '04:00'); // dimanche 20:00 → lundi 04:00
        $morning = $this->tpl('03:00', '11:00'); // lundi 03:00-11:00
        $wt = $this->week('nominative', [
            ['day_index' => 0, 'schedule_template_id' => $morning->id, 'kind' => 'nominative', 'employee_id' => $e1->id],
            ['day_index' => 6, 'schedule_template_id' => $night->id, 'kind' => 'nominative', 'employee_id' => $e1->id],
        ]);
        $res = $this->r()->commit($this->company->id, $wt->id, $this->first, '2', [], null, null, null, 1);
        // S1 : lundi + dimanche nuit = 2 ; S2 : dimanche nuit OK, mais lundi chevauche la nuit de S1 → bloqué.
        $this->assertSame(3, $res['created_count']);
        $this->assertTrue(collect($res['blocked'])->contains(fn ($b) => $b['reason'] === ApplyWeekTemplateUseCase::BLOCK_OVERLAP),
            'Le lundi de S2 chevauche le shift nocturne de dimanche S1 (cumulatif inter-semaines).');
    }

    // --- 8. Prévisualisation globale ---
    public function test_global_preview_summary(): void
    {
        $e1 = $this->emp();
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $prev = $this->r()->preview($this->company->id, $wt->id, $this->first, '4', [], null, 1);
        $this->assertSame(4, $prev['global']['weeks']);
        $this->assertSame(4, $prev['global']['applicable']);
        $this->assertCount(4, $prev['weeks']);
    }

    // --- 9. Idempotence : ré-appliquer → 0 doublon ---
    public function test_idempotence(): void
    {
        $e1 = $this->emp();
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $r1 = $this->r()->commit($this->company->id, $wt->id, $this->first, '4', [], null, null, null, 1);
        $this->assertSame(4, $r1['created_count']);
        $r2 = $this->r()->commit($this->company->id, $wt->id, $this->first, '4', [], null, null, null, 1);
        $this->assertSame(0, $r2['created_count']);
    }

    // --- 10. Rollback ATOMIQUE GLOBAL : échec de matérialisation → 0 shift sur TOUTES les semaines ---
    public function test_atomic_rollback_across_weeks(): void
    {
        $e1 = $this->emp();
        // Modèle avec composition hors bornes (écrite directement) → la matérialisation lève à la création.
        $bad = ScheduleTemplate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'BAD '.uniqid(), 'start_time' => '09:00', 'end_time' => '12:00',
            'metadata' => ['composition' => ['schema_version' => 1, 'activities' => [['id' => 'a1', 'label' => 'X', 'offset_minutes' => 120, 'duration_minutes' => 120, 'priority' => 'normal', 'non_exclusive' => false]], 'breaks' => []]],
        ]);
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $bad->id, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        try {
            $this->r()->commit($this->company->id, $wt->id, $this->first, '4', [], null, null, null, 1);
            $this->fail('La matérialisation aurait dû échouer.');
        } catch (\DomainException $e) {
            // attendu
        }
        $this->assertSame(0, Shift::withoutGlobalScopes()->where('employee_id', $e1->id)->count(), 'Aucun shift sur aucune semaine.');
    }

    // --- 11. Undo global + 12. refus si publié (HTTP) ---
    public function test_undo_global_and_refuse_if_published(): void
    {
        $e1 = $this->emp();
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $m = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $h = ['X-Company-Id' => $this->company->id];
        $ap = $this->actingAs($m)->postJson("/api/workforce/planning/week-templates/{$wt->id}/repeat-apply", ['first_week_date' => $this->first, 'horizon' => '4'], $h)->assertCreated()->json();
        $this->assertSame(4, $ap['created_count']);

        $op = \App\Core\Workforce\PlanningOperation::find($ap['operation_id']);
        $this->assertSame('repeat_week_template', $op->type);
        app(PlanningUndoRedoUseCase::class)->undo($op, $m->id);
        $this->assertSame(0, Shift::withoutGlobalScopes()->whereIn('id', $ap['created'])->count(), 'Toutes les semaines annulées.');

        // Refus si publié.
        $ap2 = $this->actingAs($m)->postJson("/api/workforce/planning/week-templates/{$wt->id}/repeat-apply", ['first_week_date' => '2027-02-01', 'horizon' => '2'], $h)->assertCreated()->json();
        app(PublishShiftsUseCase::class)->execute($this->company->id, [$ap2['created'][0]]);
        $op2 = \App\Core\Workforce\PlanningOperation::find($ap2['operation_id']);
        $this->expectException(\DomainException::class);
        app(PlanningUndoRedoUseCase::class)->undo($op2, $m->id);
    }

    // --- 13. Concurrence : conflit injecté entre preview et commit → bloqué, pas d'écrasement ---
    public function test_concurrency_revalidation(): void
    {
        $e1 = $this->emp();
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $prev = $this->r()->preview($this->company->id, $wt->id, $this->first, '2', [], null, 1);
        $this->assertSame('applicable', $prev['weeks'][0]['nominative'][0]['status']);

        // Un autre manager crée un shift chevauchant sur S1 APRÈS le preview.
        $d0 = $prev['weeks'][0]['nominative'][0]['date'];
        app(\App\Modules\Workforce\UseCases\CreateShiftUseCase::class)->execute(
            companyId: $this->company->id, employeeId: $e1->id, date: $d0,
            startAt: "$d0 09:00:00", endAt: "$d0 17:00:00", timezone: 'Europe/Paris', scheduleTemplateId: $this->tplDay,
        );
        $res = $this->r()->commit($this->company->id, $wt->id, $this->first, '2', [], null, null, null, 1);
        // S1 bloqué (chevauchement) ; S2 créé. Aucun écrasement.
        $this->assertSame(1, $res['created_count']);
        $this->assertTrue(collect($res['blocked'])->contains(fn ($b) => $b['reason'] === ApplyWeekTemplateUseCase::BLOCK_OVERLAP));
    }

    // --- 14. Tenant + manager scope ---
    public function test_tenant_and_manager_scope(): void
    {
        $e1 = $this->emp();
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $res = $this->r()->commit($this->company->id, $wt->id, $this->first, '2', [], [999999], null, null, 1);
        $this->assertSame(0, $res['created_count']);

        Model::unguard();
        $other = Company::create(['name' => 'O', 'slug' => 'o-m4', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $foreign = WeekTemplate::create(['company_id' => $other->id, 'name' => 'F', 'strategy' => 'nominative', 'scope' => 'company']);
        Model::reguard();
        $this->expectException(\DomainException::class);
        $this->r()->commit($this->company->id, $foreign->id, $this->first, '2', [], null, null, null, 1);
    }

    // --- 15. Volume : au-delà du plafond → refus ---
    public function test_volume_cap(): void
    {
        $this->emp();
        $wt = $this->week('needs', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'needs', 'headcount' => 700]]);
        $this->expectException(\DomainException::class); // 700 × 8 = 5600 > 5000
        $this->r()->preview($this->company->id, $wt->id, $this->first, '8', [], null, 1);
    }

    // --- 16. TimeEntry intact + pas d'effet rétroactif ---
    public function test_timeentry_and_no_retroactive(): void
    {
        $e1 = $this->emp();
        $wt = $this->week('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $res = $this->r()->commit($this->company->id, $wt->id, $this->first, '2', [], null, null, null, 1);
        $this->assertSame(0, \App\Core\Workforce\TimeEntry::withoutGlobalScopes()->where('employee_id', $e1->id)->count());
        $shift = Shift::withoutGlobalScopes()->find($res['created'][0]);
        $snap = $shift->template_snapshot;
        ScheduleTemplate::withoutGlobalScopes()->find($this->tplDay)->update(['start_time' => '06:00', 'name' => 'X']);
        $this->assertSame($snap, $shift->fresh()->template_snapshot);
    }
}
