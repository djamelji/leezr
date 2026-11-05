<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired;
use App\Modules\Workforce\UseCases\CopyShiftsUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning Lot 4B.3-A (ADR-721) — copie de shifts complets.
 * Vérifie : copie fidèle (snapshot/pauses/activités décalées, nuit), copie de semaine (décalage jour,
 * agnostique au 1er jour), collision publiée non écrasée, absence cible, sureffactif CUMULATIF,
 * atomicité (rollback), scope, aucun impact TimeEntry.
 */
class WorkforceCopyShiftsLot4B3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;
    private LeaveType $leaveType;

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
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'CP', 'slug' => 'cp-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#3b82f6', 'enabled' => true]);
        $this->leaveType = LeaveType::create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'CP', 'enabled' => true]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emp(): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);

        return $e->fresh();
    }

    private function sourceShift(int $empId, string $date, string $start, string $end, array $snapshot = []): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $empId, 'date' => $date,
            'start_at' => "$date $start:00", 'end_at' => ($end < $start ? Carbon::parse("$date $end:00")->addDay() : Carbon::parse("$date $end:00")),
            'timezone' => 'Europe/Paris', 'status' => Shift::STATUS_DRAFT,
            'template_snapshot' => array_merge(['coverage_category_id' => $this->route->id, 'break_duration_minutes' => 30], $snapshot),
        ]);
    }

    private function op(int $sourceId, string $targetDate, ?int $targetEmp = null): array
    {
        return ['source_shift_id' => $sourceId, 'target_employee_id' => $targetEmp, 'target_date' => $targetDate];
    }

    public function test_copy_shift_with_activities_breaks_and_category(): void
    {
        $e = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '09:00', '17:00');
        ScheduledActivity::create(['company_id' => $this->company->id, 'shift_id' => $src->id, 'employee_id' => $e->id, 'activity_type' => 'route', 'coverage_category_id' => $this->route->id, 'label' => 'Tournée A', 'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 12:00:00', 'status' => ScheduledActivity::STATUS_PLANNED]);

        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-03')], null, null, null, 1);
        $this->assertSame(1, $res['created_count']);

        $new = Shift::withoutGlobalScopes()->find($res['created'][0]);
        $this->assertSame('2026-11-03', $new->date->toDateString());
        $this->assertSame(30, (int) $new->template_snapshot['break_duration_minutes'], 'Pauses conservées.');
        $this->assertSame($this->route->id, (int) $new->template_snapshot['coverage_category_id'], 'Catégorie conservée.');
        $this->assertSame('09:00:00', $new->start_at->format('H:i:s'), 'Horaires conservés.');
        $act = ScheduledActivity::withoutGlobalScopes()->where('shift_id', $new->id)->first();
        $this->assertNotNull($act, 'Activité clonée.');
        $this->assertSame('2026-11-03 09:00:00', $act->start_at->toDateTimeString(), 'Activité décalée au bon jour (position relative).');
        $this->assertSame('copy', $act->source);
    }

    public function test_copy_nocturnal_shift_preserves_overnight(): void
    {
        $e = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '22:00', '06:00'); // nuit → fin le lendemain
        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-05')], null, null, null, 1);
        $new = Shift::withoutGlobalScopes()->find($res['created'][0]);
        $this->assertSame('2026-11-05 22:00:00', $new->start_at->toDateTimeString());
        $this->assertSame('2026-11-06 06:00:00', $new->end_at->toDateTimeString(), 'Shift nocturne : fin le lendemain préservée.');
    }

    public function test_week_copy_shift_day_delta_is_weekstart_agnostic(): void
    {
        // Copie « semaine » = décalage de 7 jours ; positions relatives conservées quel que soit le 1er jour.
        $e = $this->emp();
        $mon = $this->sourceShift($e->id, '2026-11-02', '09:00', '12:00');
        $wed = $this->sourceShift($e->id, '2026-11-04', '09:00', '12:00');
        $ops = [$this->op($mon->id, '2026-11-09'), $this->op($wed->id, '2026-11-11')];
        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, $ops, null, null, null, 1);
        $this->assertSame(2, $res['created_count']);
        $this->assertSame(1, Shift::withoutGlobalScopes()->where('employee_id', $e->id)->whereDate('date', '2026-11-09')->count());
        $this->assertSame(1, Shift::withoutGlobalScopes()->where('employee_id', $e->id)->whereDate('date', '2026-11-11')->count());
    }

    public function test_published_collision_is_blocked_never_overwritten(): void
    {
        $e = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '09:00', '17:00');
        // Cible : un shift PUBLIÉ chevauchant existe déjà le 03.
        Shift::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-11-03', 'start_at' => '2026-11-03 09:00:00', 'end_at' => '2026-11-03 17:00:00', 'timezone' => 'Europe/Paris', 'status' => Shift::STATUS_PUBLISHED, 'published_at' => now(), 'template_snapshot' => []]);

        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-03')], null, null, null, 1);
        $this->assertSame(0, $res['created_count']);
        $this->assertSame('published_collision', $res['blocked'][0]['reason']);
        $this->assertSame(2, Shift::withoutGlobalScopes()->count(), 'Le publié n\'est pas écrasé, rien d\'ajouté.');
    }

    public function test_absent_target_is_blocked(): void
    {
        $e = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '09:00', '17:00');
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-11-03', 'date_to' => '2026-11-03', 'status' => LeaveRequest::STATUS_APPROVED, 'days_count_hundredths' => 100]);

        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-03')], null, null, null, 1);
        $this->assertSame(0, $res['created_count']);
        $this->assertSame('absent', $res['blocked'][0]['reason']);
    }

    public function test_cumulative_overstaffing_blocks_then_confirms(): void
    {
        CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 1, 'coverage_category_id' => $this->route->id, 'enabled' => true]);
        // 1 déjà affecté le 03 ; on copie 1 source vers le 03 → projeté 2/1.
        $already = $this->emp();
        Shift::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $already->id, 'date' => '2026-11-03', 'start_at' => '2026-11-03 09:00:00', 'end_at' => '2026-11-03 17:00:00', 'timezone' => 'Europe/Paris', 'status' => Shift::STATUS_DRAFT, 'template_snapshot' => ['coverage_category_id' => $this->route->id]]);
        $e = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '09:00', '17:00');

        try {
            app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-03')], null, null, null, 1);
            $this->fail('Expected OverstaffingConfirmationRequired.');
        } catch (OverstaffingConfirmationRequired $ex) {
            $sig = $ex->signature;
        }
        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-03')], null, $sig, null, 1);
        $this->assertSame(1, $res['created_count']);
    }

    public function test_atomic_rollback_on_in_batch_conflict(): void
    {
        // Deux copies vers la MÊME cellule → la 2e chevauche la 1re (créée dans la transaction) → rollback total.
        $e = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '09:00', '17:00');
        ScheduledActivity::create(['company_id' => $this->company->id, 'shift_id' => $src->id, 'employee_id' => $e->id, 'activity_type' => 'x', 'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 10:00:00', 'status' => ScheduledActivity::STATUS_PLANNED]);
        $ops = [$this->op($src->id, '2026-11-03'), $this->op($src->id, '2026-11-03')];

        try {
            app(CopyShiftsUseCase::class)->commit($this->company->id, $ops, null, null, null, 1);
            $this->fail('Expected DomainException (overlap).');
        } catch (\DomainException $ex) {
            // attendu
        }
        $this->assertSame(1, Shift::withoutGlobalScopes()->count(), 'Rollback : aucun nouveau shift.');
        $this->assertSame(1, ScheduledActivity::withoutGlobalScopes()->count(), 'Rollback : aucune activité clonée (pas de shift incomplet).');
    }

    public function test_scope_blocks_out_of_scope_target(): void
    {
        $e = $this->emp();
        $other = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '09:00', '17:00');
        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-03', $other->id)], [$e->id], null, null, 1);
        $this->assertSame(0, $res['created_count']);
        $this->assertSame('out_of_scope', $res['blocked'][0]['reason']);
    }

    public function test_copy_does_not_touch_timeentry(): void
    {
        $e = $this->emp();
        $src = $this->sourceShift($e->id, '2026-11-02', '09:00', '17:00');
        $before = TimeEntry::withoutGlobalScopes()->count();
        app(CopyShiftsUseCase::class)->commit($this->company->id, [$this->op($src->id, '2026-11-03')], null, null, null, 1);
        $this->assertSame($before, TimeEntry::withoutGlobalScopes()->count());
    }
}
