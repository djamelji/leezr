<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PlanningOperation;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Modules\Workforce\Services\PlanningOperationRecorder;
use App\Modules\Workforce\UseCases\CopyShiftsUseCase;
use App\Modules\Workforce\UseCases\CreateShiftUseCase;
use App\Modules\Workforce\UseCases\PlanningUndoRedoUseCase;
use App\Modules\Workforce\UseCases\UpdateShiftUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning Lot 4B.3-B (ADR-722) — annuler / rétablir (commandes inverses persistées).
 */
class WorkforceUndoRedoLot4B3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private int $uid;

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
        $this->company = Company::create(['name' => 'UR', 'slug' => 'ur-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        Model::reguard();
        $this->uid = \App\Core\Models\User::factory()->create()->id;
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

    private function createShift(int $empId, string $date = '2026-11-02'): Shift
    {
        return app(CreateShiftUseCase::class)->execute(
            companyId: $this->company->id, employeeId: $empId, date: $date,
            startAt: "$date 09:00:00", endAt: "$date 12:00:00", timezone: 'Europe/Paris',
        );
    }

    private function recordCreate(Shift $shift): PlanningOperation
    {
        return app(PlanningOperationRecorder::class)->recordCreated($this->company->id, $this->uid, 'create_shift', [$shift->id], [
            'employee_id' => $shift->employee_id, 'date' => $shift->date->toDateString(),
            'start_at' => $shift->start_at->toDateTimeString(), 'end_at' => $shift->end_at->toDateTimeString(), 'timezone' => 'Europe/Paris',
        ]);
    }

    private function uc(): PlanningUndoRedoUseCase
    {
        return app(PlanningUndoRedoUseCase::class);
    }

    public function test_undo_creation_deletes_draft(): void
    {
        $shift = $this->createShift($this->emp()->id);
        $op = $this->recordCreate($shift);
        $this->uc()->undo($op, $this->uid);
        $this->assertNull(Shift::withoutGlobalScopes()->find($shift->id));
        $this->assertSame('undone', $op->fresh()->status);
    }

    public function test_redo_creation_creates_new_draft_not_old_id(): void
    {
        $shift = $this->createShift($this->emp()->id);
        $op = $this->recordCreate($shift);
        $this->uc()->undo($op, $this->uid);
        $res = $this->uc()->redo($op->fresh(), $this->uid);
        $this->assertNotEmpty($res['created']);
        $this->assertNotEquals($shift->id, $res['created'][0], 'Rétablir crée un NOUVEAU shift (jamais l\'ancien ID).');
        $this->assertSame('active', $op->fresh()->status);
    }

    public function test_undo_move_restores_previous_coords(): void
    {
        $e = $this->emp();
        $shift = $this->createShift($e->id, '2026-11-02');
        $before = PlanningOperationRecorder::snapshot($shift);
        app(UpdateShiftUseCase::class)->execute($shift, ['date' => '2026-11-03', 'start_at' => '2026-11-03 09:00:00', 'end_at' => '2026-11-03 12:00:00']);
        $op = app(PlanningOperationRecorder::class)->recordMove($this->company->id, $this->uid, $before, $shift->fresh());

        $this->uc()->undo($op, $this->uid);
        $this->assertSame('2026-11-02', $shift->fresh()->date->toDateString(), 'Déplacement annulé → retour au jour initial.');
    }

    public function test_undo_move_refuses_on_concurrent_change(): void
    {
        $e = $this->emp();
        $shift = $this->createShift($e->id, '2026-11-02');
        $before = PlanningOperationRecorder::snapshot($shift);
        app(UpdateShiftUseCase::class)->execute($shift, ['date' => '2026-11-03', 'start_at' => '2026-11-03 09:00:00', 'end_at' => '2026-11-03 12:00:00']);
        $op = app(PlanningOperationRecorder::class)->recordMove($this->company->id, $this->uid, $before, $shift->fresh());

        // Manager B modifie le shift entre-temps (nouvelle version).
        Carbon::setTestNow(Carbon::now()->addMinute());
        app(UpdateShiftUseCase::class)->execute($shift->fresh(), ['start_at' => '2026-11-03 10:00:00', 'end_at' => '2026-11-03 13:00:00']);

        $this->expectException(\DomainException::class);
        $this->uc()->undo($op->fresh(), $this->uid);
    }

    public function test_undo_refused_if_shift_published(): void
    {
        $shift = $this->createShift($this->emp()->id);
        $op = $this->recordCreate($shift);
        $shift->update(['status' => Shift::STATUS_PUBLISHED, 'published_at' => now()]);

        try {
            $this->uc()->undo($op, $this->uid);
            $this->fail('Expected refusal.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('draft', strtolower($e->getMessage()));
        }
        $this->assertNotNull(Shift::withoutGlobalScopes()->find($shift->id), 'Le shift publié n\'est pas supprimé.');
    }

    public function test_undo_copy_week_is_atomic_refuses_if_one_published(): void
    {
        $e = $this->emp();
        $s1 = $this->createShift($e->id, '2026-11-02');
        $s2 = $this->createShift($e->id, '2026-11-04');
        $res = app(CopyShiftsUseCase::class)->commit($this->company->id, [
            ['source_shift_id' => $s1->id, 'target_employee_id' => null, 'target_date' => '2026-11-09'],
            ['source_shift_id' => $s2->id, 'target_employee_id' => null, 'target_date' => '2026-11-11'],
        ], null, null, null, $this->uid);
        $op = app(PlanningOperationRecorder::class)->recordCreated($this->company->id, $this->uid, 'copy_week', $res['created'], []);
        $this->assertCount(2, $res['created']);

        // Un des deux créés devient publié → l'annulation globale doit REFUSER (pas 1/2 en silence).
        Shift::withoutGlobalScopes()->whereKey($res['created'][0])->update(['status' => Shift::STATUS_PUBLISHED, 'published_at' => now()]);
        try {
            $this->uc()->undo($op, $this->uid);
            $this->fail('Expected atomic refusal.');
        } catch (\DomainException $e) {
            // attendu
        }
        $this->assertSame(2, Shift::withoutGlobalScopes()->whereIn('id', $res['created'])->count(), 'Aucune suppression partielle.');
    }

    public function test_double_undo_is_idempotent(): void
    {
        $shift = $this->createShift($this->emp()->id);
        $op = $this->recordCreate($shift);
        $this->uc()->undo($op, $this->uid);
        $this->expectException(\DomainException::class);
        $this->uc()->undo($op->fresh(), $this->uid); // déjà annulée
    }

    public function test_cross_user_cannot_undo(): void
    {
        $shift = $this->createShift($this->emp()->id);
        $op = $this->recordCreate($shift);
        $this->expectException(\DomainException::class);
        $this->uc()->undo($op, 999); // autre utilisateur
    }

    public function test_undo_does_not_touch_timeentry(): void
    {
        $shift = $this->createShift($this->emp()->id);
        $op = $this->recordCreate($shift);
        $before = TimeEntry::withoutGlobalScopes()->count();
        $this->uc()->undo($op, $this->uid);
        $this->assertSame($before, TimeEntry::withoutGlobalScopes()->count());
    }

    public function test_undo_creation_also_removes_cloned_activities(): void
    {
        $e = $this->emp();
        $shift = $this->createShift($e->id);
        ScheduledActivity::create(['company_id' => $this->company->id, 'shift_id' => $shift->id, 'employee_id' => $e->id, 'activity_type' => 'x', 'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 10:00:00', 'status' => ScheduledActivity::STATUS_PLANNED]);
        $op = $this->recordCreate($shift);
        $this->uc()->undo($op, $this->uid);
        $this->assertSame(0, ScheduledActivity::withoutGlobalScopes()->where('shift_id', $shift->id)->count());
    }
}
