<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\CoverageReadModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning Lot 3 (ADR-716) — couverture recalculée avec indisponibilités CONFIRMÉES.
 * Vérifie : un congé approuvé retire le salarié de la couverture ce jour-là ; une demande EN ATTENTE
 * ne retire JAMAIS la couverture confirmée ; pas de double-compte absent/remplaçant.
 */
class WorkforceCoverageAbsenceLot3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private LeaveType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        Model::unguard();
        \App\Core\Jobdomains\Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        \App\Core\Markets\Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'L3', 'slug' => 'l3-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->type = LeaveType::create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'Congés payés', 'is_paid' => true, 'enabled' => true]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
    }

    private function shift(Employee $e): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-11-02',
            'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 17:00:00', 'timezone' => 'Europe/Paris',
            'status' => Shift::STATUS_PUBLISHED, 'published_at' => now(), 'template_snapshot' => [],
        ]);
    }

    private function leave(Employee $e, string $status): LeaveRequest
    {
        return LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->type->id,
            'date_from' => '2026-11-02', 'date_to' => '2026-11-02', 'status' => $status,
            'days_count_hundredths' => 100,
        ]);
    }

    private function assignedFor(int $reqId): int
    {
        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');

        return collect($r['requirements'])->firstWhere('requirement_id', $reqId)['assigned'];
    }

    public function test_confirmed_absence_removes_employee_from_coverage(): void
    {
        $req = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 2, 'enabled' => true]);
        $e1 = $this->employee();
        $e2 = $this->employee();
        $this->shift($e1);
        $this->shift($e2);

        $this->assertSame(2, $this->assignedFor($req->id), 'Deux présents → 2/2.');

        // e1 en congé APPROUVÉ → ne compte plus.
        $this->leave($e1, LeaveRequest::STATUS_APPROVED);
        $this->assertSame(1, $this->assignedFor($req->id), 'Absence confirmée retirée de la couverture → 1/2.');
    }

    public function test_pending_leave_does_not_remove_confirmed_coverage(): void
    {
        $req = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 1, 'enabled' => true]);
        $e = $this->employee();
        $this->shift($e);
        // Demande EN ATTENTE → la couverture confirmée reste.
        $this->leave($e, LeaveRequest::STATUS_PENDING);

        $this->assertSame(1, $this->assignedFor($req->id), 'Une demande en attente ne retire PAS la couverture.');
    }

    public function test_absent_and_replacement_not_double_counted(): void
    {
        // e1 absent (approuvé), e2 remplaçant réellement affecté → 1 seul compte (pas 2).
        $req = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 1, 'enabled' => true]);
        $e1 = $this->employee();
        $e2 = $this->employee();
        $this->shift($e1);
        $this->shift($e2);
        $this->leave($e1, LeaveRequest::STATUS_APPROVED);

        $this->assertSame(1, $this->assignedFor($req->id), 'Absent non compté, remplaçant compté → 1 (pas de double-compte).');
    }
}
