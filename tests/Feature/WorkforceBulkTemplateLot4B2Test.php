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
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\UseCases\BulkApplyTemplateUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning Lot 4B.2 (ADR-720) — application groupée de modèle.
 * Vérifie : classification par cellule (applicable/bloquée + motif), sureffectif CUMULATIF (15/12 sur un
 * lot), confirmation serveur, atomicité tout-ou-rien (rollback), brouillons (aucune notif), scope.
 */
class WorkforceBulkTemplateLot4B2Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;
    private ScheduleTemplate $tpl;
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
        $this->company = Company::create(['name' => 'BK', 'slug' => 'bk-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#3b82f6', 'enabled' => true]);
        $this->tpl = ScheduleTemplate::create(['company_id' => $this->company->id, 'name' => 'Route 09-17', 'start_time' => '09:00', 'end_time' => '17:00', 'coverage_category_id' => $this->route->id, 'color' => '#3b82f6']);
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

    private function routeShift(int $empId, string $date = '2026-11-02'): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $empId, 'date' => $date,
            'start_at' => "$date 09:00:00", 'end_at' => "$date 17:00:00", 'timezone' => 'Europe/Paris',
            'status' => Shift::STATUS_DRAFT, 'template_snapshot' => ['coverage_category_id' => $this->route->id],
        ]);
    }

    private function cellsFor(array $empIds, string $date = '2026-11-02'): array
    {
        return array_map(fn ($id) => ['employee_id' => $id, 'date' => $date], $empIds);
    }

    public function test_preview_classifies_blocked_cells(): void
    {
        $ok = $this->emp();
        $absent = $this->emp();
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $absent->id, 'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-11-02', 'date_to' => '2026-11-02', 'status' => LeaveRequest::STATUS_APPROVED, 'days_count_hundredths' => 100]);
        $busy = $this->emp();
        $this->routeShift($busy->id); // chevauchement 09-17

        $res = app(BulkApplyTemplateUseCase::class)->preview($this->company->id, $this->tpl->id, $this->cellsFor([$ok->id, $absent->id, $busy->id]));

        $this->assertSame(1, $res['applicable_count']);
        $byEmp = collect($res['cells'])->keyBy('employee_id');
        $this->assertSame('applicable', $byEmp[$ok->id]['status']);
        $this->assertSame('absent', $byEmp[$absent->id]['reason']);
        $this->assertSame('overlap', $byEmp[$busy->id]['reason']);
    }

    public function test_cumulative_overstaffing_on_batch(): void
    {
        // Besoin 12, 10 déjà affectés, lot de 5 → projeté 15/12.
        CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 12, 'coverage_category_id' => $this->route->id, 'enabled' => true]);
        for ($i = 0; $i < 10; $i++) {
            $this->routeShift($this->emp()->id);
        }
        $newFive = collect(range(1, 5))->map(fn () => $this->emp()->id)->all();

        $res = app(BulkApplyTemplateUseCase::class)->preview($this->company->id, $this->tpl->id, $this->cellsFor($newFive));
        $this->assertTrue($res['overstaffing']['overstaffed']);
        $this->assertSame(12, $res['overstaffing']['impacts'][0]['required']);
        $this->assertSame(15, $res['overstaffing']['impacts'][0]['projected'], 'Projection CUMULATIVE du lot : 10 + 5 = 15.');
    }

    public function test_commit_blocked_without_ack_then_creates_with_ack_as_drafts(): void
    {
        CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 12, 'coverage_category_id' => $this->route->id, 'enabled' => true]);
        for ($i = 0; $i < 10; $i++) {
            $this->routeShift($this->emp()->id);
        }
        $newFive = collect(range(1, 5))->map(fn () => $this->emp()->id)->all();
        $cells = $this->cellsFor($newFive);

        // Sans ack → OverstaffingConfirmationRequired, rien créé.
        try {
            app(BulkApplyTemplateUseCase::class)->commit($this->company->id, $this->tpl->id, $cells, null, null, null, 1);
            $this->fail('Expected OverstaffingConfirmationRequired.');
        } catch (\App\Modules\Workforce\Exceptions\OverstaffingConfirmationRequired $e) {
            $signature = $e->signature;
        }
        $this->assertSame(10, Shift::withoutGlobalScopes()->count(), 'Aucun shift créé sans confirmation.');

        // Avec ack valide → 5 brouillons créés.
        $res = app(BulkApplyTemplateUseCase::class)->commit($this->company->id, $this->tpl->id, $cells, null, $signature, null, 1);
        $this->assertSame(5, $res['created_count']);
        $this->assertSame(15, Shift::withoutGlobalScopes()->count());
        $this->assertSame(5, Shift::withoutGlobalScopes()->whereIn('id', $res['created'])->where('status', Shift::STATUS_DRAFT)->count(), 'Les créations sont des BROUILLONS.');
    }

    public function test_commit_is_atomic_rollback_on_conflict(): void
    {
        // Deux cellules pour le MÊME salarié le MÊME jour : la 2e chevauche la 1re créée dans la transaction
        // → exception → rollback total (atomicité). Aucun besoin → pas de sureffectif.
        $e = $this->emp();
        $cells = [
            ['employee_id' => $e->id, 'date' => '2026-11-02'],
            ['employee_id' => $e->id, 'date' => '2026-11-02'],
        ];

        try {
            app(BulkApplyTemplateUseCase::class)->commit($this->company->id, $this->tpl->id, $cells, null, null, null, 1);
            $this->fail('Expected a DomainException (overlap within batch).');
        } catch (\DomainException $e2) {
            // attendu
        }
        $this->assertSame(0, Shift::withoutGlobalScopes()->count(), 'Rollback total : aucune création partielle.');
    }

    public function test_scope_blocks_out_of_scope_cells(): void
    {
        $visible = $this->emp();
        $hidden = $this->emp();
        $res = app(BulkApplyTemplateUseCase::class)->preview($this->company->id, $this->tpl->id, $this->cellsFor([$visible->id, $hidden->id]), [$visible->id]);
        $byEmp = collect($res['cells'])->keyBy('employee_id');
        $this->assertSame('applicable', $byEmp[$visible->id]['status']);
        $this->assertSame('out_of_scope', $byEmp[$hidden->id]['reason']);
    }

    public function test_no_requirement_bulk_creates_without_overstaffing(): void
    {
        $five = collect(range(1, 5))->map(fn () => $this->emp()->id)->all();
        $res = app(BulkApplyTemplateUseCase::class)->commit($this->company->id, $this->tpl->id, $this->cellsFor($five), null, null, null, 1);
        $this->assertSame(5, $res['created_count']);
        $this->assertSame(5, Shift::withoutGlobalScopes()->where('status', Shift::STATUS_DRAFT)->count());
    }
}
