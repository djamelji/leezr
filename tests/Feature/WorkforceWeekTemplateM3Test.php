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
use App\Core\Workforce\Shift;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use App\Modules\Workforce\Services\ScheduleTemplateCompositionValidator;
use App\Modules\Workforce\UseCases\ApplyWeekTemplateUseCase;
use App\Modules\Workforce\UseCases\CreateScheduleTemplateUseCase;
use App\Modules\Workforce\UseCases\PlanningUndoRedoUseCase;
use App\Modules\Workforce\UseCases\PublishShiftsUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Refonte Smart Planning — M3 (ADR-726) : semaines types nominatives / par besoins / mixtes.
 */
class WorkforceWeekTemplateM3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;
    private \App\Core\Workforce\LeaveType $leaveType;
    private int $tplDay;   // modèle enrichi 09-17
    private int $tplEve;   // modèle 18-20 (pour multi-shifts/jour)
    private string $target = '2026-11-18'; // une semaine cible

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
        $this->company = Company::create(['name' => 'M3', 'slug' => 'm3-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#3b82f6', 'enabled' => true]);
        $this->leaveType = \App\Core\Workforce\LeaveType::create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'CP', 'enabled' => true]);
        Model::reguard();

        $this->tplDay = $this->enrichedTemplate('09:00', '17:00')->id;
        $this->tplEve = $this->enrichedTemplate('18:00', '20:00', false)->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enrichedTemplate(string $start, string $end, bool $withActs = true): \App\Core\Workforce\ScheduleTemplate
    {
        $comp = $withActs ? app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize([
            'schema_version' => 1,
            'activities' => [
                ['label' => 'Brief', 'coverage_category_id' => $this->route->id, 'offset_minutes' => 0, 'duration_minutes' => 30],
                ['label' => 'Tournée', 'coverage_category_id' => $this->route->id, 'offset_minutes' => 30, 'duration_minutes' => 180],
            ],
            'breaks' => [['offset_minutes' => 210, 'duration_minutes' => 60]],
        ], $start, $end) : null;

        return app(CreateScheduleTemplateUseCase::class)->execute(
            companyId: $this->company->id, name: 'TPL '.uniqid(), startTime: $start, endTime: $end,
            coverageCategoryId: $this->route->id, composition: $comp,
        );
    }

    private function emp(bool $contract = true): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        if ($contract) {
            EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        }

        return $e->fresh();
    }

    /** @param array<int,array<string,mixed>> $lines */
    private function weekTemplate(string $strategy, array $lines): WeekTemplate
    {
        $wt = WeekTemplate::create(['company_id' => $this->company->id, 'name' => 'WT '.uniqid(), 'strategy' => $strategy, 'scope' => 'company', 'enabled' => true]);
        $o = 0;
        foreach ($lines as $ln) {
            WeekTemplateLine::create(array_merge(['company_id' => $this->company->id, 'week_template_id' => $wt->id, 'sort_order' => $o++], $ln));
        }

        return $wt->fresh();
    }

    private function use_(): ApplyWeekTemplateUseCase
    {
        return app(ApplyWeekTemplateUseCase::class);
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

    // --- 1. Nominative : application matérialise activités + pauses ---
    public function test_nominative_apply_materializes_activities(): void
    {
        $e1 = $this->emp();
        $e2 = $this->emp();
        $wt = $this->weekTemplate('nominative', [
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id],
            ['day_index' => 1, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id],
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e2->id],
        ]);
        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $this->assertSame(3, $res['created_count']);
        $this->assertSame(0, $res['unfilled']);
        $this->assertSame(2 * 3, ScheduledActivity::withoutGlobalScopes()->whereIn('shift_id', $res['created'])->count());
    }

    // --- 2. Par besoins : candidats + poste non pourvu ---
    public function test_needs_preview_candidates_and_unfilled(): void
    {
        $e1 = $this->emp();
        $this->emp(); // 2 candidats possibles
        $wt = $this->weekTemplate('needs', [
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'needs', 'headcount' => 3],
        ]);
        $prev = $this->use_()->preview($this->company->id, $wt->id, $this->target, null);
        $this->assertSame(3, $prev['summary']['to_confirm']);
        $this->assertGreaterThanOrEqual(2, count($prev['needs'][0]['candidates']));

        // Affecte 1 seul salarié sur un besoin de 3 → 1 créé, 2 non pourvus.
        $lineId = $wt->lines()->first()->id;
        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [['line_id' => $lineId, 'employee_id' => $e1->id]], null, null, null, 1);
        $this->assertSame(1, $res['created_count']);
        $this->assertSame(2, $res['unfilled'], 'Les postes non attribués restent non pourvus, jamais des Shifts.');
    }

    // --- 3. Mixte : nominatif + besoin dans un seul processus ---
    public function test_mixed_strategy(): void
    {
        $e1 = $this->emp();
        $e2 = $this->emp();
        $wt = $this->weekTemplate('mixed', [
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id],
            ['day_index' => 0, 'schedule_template_id' => $this->tplEve, 'kind' => 'needs', 'headcount' => 1],
        ]);
        $needsLine = $wt->lines()->where('kind', 'needs')->first()->id;
        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [['line_id' => $needsLine, 'employee_id' => $e2->id]], null, null, null, 1);
        $this->assertSame(2, $res['created_count']);
        $this->assertSame(0, $res['unfilled']);
    }

    // --- 4. Plusieurs shifts le même jour (modèles disjoints) ---
    public function test_multiple_shifts_same_day(): void
    {
        $e1 = $this->emp();
        $wt = $this->weekTemplate('nominative', [
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id],
            ['day_index' => 0, 'schedule_template_id' => $this->tplEve, 'kind' => 'nominative', 'employee_id' => $e1->id],
        ]);
        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $this->assertSame(2, $res['created_count']);
    }

    // --- 5. Premier jour de semaine Company ≠ lundi ---
    public function test_company_week_start_not_monday(): void
    {
        $this->company->update(['week_start_day' => 0]); // dimanche
        $ws = $this->use_()->resolveWeekStart($this->company->fresh(), $this->target);
        $this->assertSame(0, $ws->dayOfWeek, 'La semaine commence le dimanche.');

        $e1 = $this->emp();
        $wt = $this->weekTemplate('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $res = $this->use_()->commit($this->company->fresh()->id, $wt->id, $this->target, [], null, null, null, 1);
        $shift = Shift::withoutGlobalScopes()->find($res['created'][0]);
        $this->assertSame($ws->toDateString(), $shift->date->toDateString(), 'day_index 0 = premier jour Company (dimanche).');
    }

    // --- 6. Contrat inactif → bloqué ---
    public function test_inactive_contract_blocked(): void
    {
        $e = $this->emp(contract: false);
        $wt = $this->weekTemplate('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e->id]]);
        $prev = $this->use_()->preview($this->company->id, $wt->id, $this->target, null);
        $this->assertSame('blocked', $prev['nominative'][0]['status']);
        $this->assertSame(ApplyWeekTemplateUseCase::BLOCK_NO_CONTRACT, $prev['nominative'][0]['reason']);
        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $this->assertSame(0, $res['created_count']);
    }

    // --- 7. Absence confirmée vs 8. demande en attente ---
    public function test_confirmed_absence_blocks_pending_warns(): void
    {
        $ws = $this->use_()->resolveWeekStart($this->company, $this->target);
        $d0 = $ws->copy()->toDateString();
        $d1 = $ws->copy()->addDay()->toDateString();

        $eAbs = $this->emp();
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $eAbs->id, 'status' => LeaveRequest::STATUS_APPROVED, 'date_from' => $d0, 'date_to' => $d0, 'leave_type_id' => $this->leaveType->id, 'days_count_hundredths' => 100]);
        $ePend = $this->emp();
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $ePend->id, 'status' => LeaveRequest::STATUS_PENDING, 'date_from' => $d1, 'date_to' => $d1, 'leave_type_id' => $this->leaveType->id, 'days_count_hundredths' => 100]);

        $wt = $this->weekTemplate('nominative', [
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $eAbs->id],
            ['day_index' => 1, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $ePend->id],
        ]);
        $prev = $this->use_()->preview($this->company->id, $wt->id, $this->target, null);
        $byEmp = collect($prev['nominative'])->keyBy('employee_id');
        $this->assertSame('blocked', $byEmp[$eAbs->id]['status']);
        $this->assertSame(ApplyWeekTemplateUseCase::BLOCK_ABSENT, $byEmp[$eAbs->id]['reason']);
        $this->assertSame('applicable', $byEmp[$ePend->id]['status']);
        $this->assertSame('pending_leave', $byEmp[$ePend->id]['warning'], 'Demande en attente = risque, pas une absence.');

        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $this->assertSame(1, $res['created_count'], 'Seul le salarié en attente est planifié (pas l’absent confirmé).');
    }

    // --- 9. Chevauchement + idempotence (double application) ---
    public function test_overlap_and_idempotence(): void
    {
        $e1 = $this->emp();
        $wt = $this->weekTemplate('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $r1 = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $this->assertSame(1, $r1['created_count']);
        // Ré-application → chevauchement, 0 créé (pas de doublon).
        $r2 = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $this->assertSame(0, $r2['created_count']);
        $this->assertSame(ApplyWeekTemplateUseCase::BLOCK_OVERLAP, $r2['blocked'][0]['reason']);
    }

    // --- 11. Undo global + 12. refus si publié ---
    public function test_undo_removes_all_and_refuses_if_published(): void
    {
        $e1 = $this->emp();
        $e2 = $this->emp();
        $wt = $this->weekTemplate('nominative', [
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id],
            ['day_index' => 1, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e2->id],
        ]);
        // via HTTP pour enregistrer l'opération réversible
        $m = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $h = ['X-Company-Id' => $this->company->id];
        $apply = $this->actingAs($m)->postJson("/api/workforce/planning/week-templates/{$wt->id}/apply", ['target_date' => $this->target], $h)->assertCreated()->json();
        $opId = $apply['operation_id'];
        $this->assertSame(2, $apply['created_count']);

        // Undo → tout supprimé (shifts + activités).
        $op = \App\Core\Workforce\PlanningOperation::find($opId);
        app(PlanningUndoRedoUseCase::class)->undo($op, $m->id);
        $this->assertSame(0, Shift::withoutGlobalScopes()->whereIn('id', $apply['created'])->count());
        $this->assertSame(0, ScheduledActivity::withoutGlobalScopes()->whereIn('shift_id', $apply['created'])->count());

        // Refus d'undo si un shift est publié.
        $apply2 = $this->actingAs($m)->postJson("/api/workforce/planning/week-templates/{$wt->id}/apply", ['target_date' => '2026-11-25'], $h)->assertCreated()->json();
        PublishShiftsUseCase::class && app(PublishShiftsUseCase::class)->execute($this->company->id, [$apply2['created'][0]]);
        $op2 = \App\Core\Workforce\PlanningOperation::find($apply2['operation_id']);
        $this->expectException(\DomainException::class);
        app(PlanningUndoRedoUseCase::class)->undo($op2, $m->id);
    }

    // --- 13. Isolation tenant (HTTP) + 14. manager scope ---
    public function test_tenant_isolation_and_manager_scope(): void
    {
        Model::unguard();
        $other = Company::create(['name' => 'O', 'slug' => 'o-m3', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $mod) {
            CompanyModuleActivationReason::create(['company_id' => $other->id, 'module_key' => $mod, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $foreignWt = WeekTemplate::create(['company_id' => $other->id, 'name' => 'Foreign', 'strategy' => 'nominative', 'scope' => 'company']);
        Model::reguard();

        $m = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $h = ['X-Company-Id' => $this->company->id];
        // Semaine type d'une autre company → 404 (preview/apply hors scope).
        $this->actingAs($m)->postJson("/api/workforce/planning/week-templates/{$foreignWt->id}/preview", ['target_date' => $this->target], $h)->assertStatus(422);

        // Manager scope : visibleIds excluant le salarié nominatif → ligne out_of_scope, non créée.
        $e1 = $this->emp();
        $wt = $this->weekTemplate('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], [999999], null, null, 1);
        $this->assertSame(0, $res['created_count']);
        $this->assertSame(ApplyWeekTemplateUseCase::BLOCK_OUT_OF_SCOPE, $res['blocked'][0]['reason']);
    }

    // --- 15. Modifier le modèle source → pas d'effet rétroactif ---
    public function test_modifying_source_template_no_retroactive(): void
    {
        $e1 = $this->emp();
        $wt = $this->weekTemplate('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $res = $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $shift = Shift::withoutGlobalScopes()->find($res['created'][0]);
        $snapBefore = $shift->template_snapshot;

        $tpl = \App\Core\Workforce\ScheduleTemplate::withoutGlobalScopes()->find($this->tplDay);
        $tpl->update(['name' => 'RENOMMÉ', 'start_time' => '06:00']);

        $this->assertSame($snapBefore, $shift->fresh()->template_snapshot, 'Snapshot figé inchangé.');
    }

    // --- 16. TimeEntry inchangé ---
    public function test_timeentry_untouched(): void
    {
        $e1 = $this->emp();
        $wt = $this->weekTemplate('nominative', [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id]]);
        $this->use_()->commit($this->company->id, $wt->id, $this->target, [], null, null, null, 1);
        $this->assertSame(0, \App\Core\Workforce\TimeEntry::withoutGlobalScopes()->where('employee_id', $e1->id)->count());
    }

    // --- 17. Capture d'une semaine existante (HTTP) ---
    public function test_capture_week(): void
    {
        $e1 = $this->emp();
        $this->use_()->commit($this->company->id,
            $this->weekTemplate('nominative', [
                ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id],
                ['day_index' => 2, 'schedule_template_id' => $this->tplEve, 'kind' => 'nominative', 'employee_id' => $e1->id],
            ])->id, $this->target, [], null, null, null, 1);

        $m = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $h = ['X-Company-Id' => $this->company->id];
        $cap = $this->actingAs($m)->postJson('/api/workforce/planning/week-templates/capture', ['name' => 'Capturée', 'target_date' => $this->target], $h)->assertCreated()->json();
        $this->assertSame('nominative', $cap['strategy']);
        $this->assertCount(2, $cap['lines']);
        $this->assertSame($e1->id, $cap['lines'][0]['employee_id']);
    }

    // --- 18. CRUD + validation + RBAC (HTTP) ---
    public function test_crud_and_rbac(): void
    {
        $e1 = $this->emp();
        $h = ['X-Company-Id' => $this->company->id];
        $body = ['name' => 'Sem TPE', 'strategy' => 'nominative', 'lines' => [
            ['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative', 'employee_id' => $e1->id],
        ]];

        $viewer = $this->user(['workforce.planning_view']);
        $this->actingAs($viewer)->getJson('/api/workforce/planning/week-templates', $h)->assertOk();
        $this->actingAs($viewer)->postJson('/api/workforce/planning/week-templates', $body, $h)->assertForbidden();

        $m = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $id = $this->actingAs($m)->postJson('/api/workforce/planning/week-templates', $body, $h)->assertCreated()->json('id');
        // Nom dupliqué
        $this->actingAs($m)->postJson('/api/workforce/planning/week-templates', $body, $h)->assertStatus(422);
        // Ligne nominative sans salarié → 422
        $this->actingAs($m)->postJson('/api/workforce/planning/week-templates', ['name' => 'X', 'lines' => [['day_index' => 0, 'schedule_template_id' => $this->tplDay, 'kind' => 'nominative']]], $h)->assertStatus(422);
        $this->actingAs($m)->deleteJson("/api/workforce/planning/week-templates/{$id}", [], $h)->assertOk();
    }
}
