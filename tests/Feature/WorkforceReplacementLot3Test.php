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
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\CoverageReadModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning Lot 3 complément (ADR-717) — remplacement guidé.
 * Vérifie : candidats scopés (absent exclu), création+publication du remplacement via UseCases existants,
 * Shift initial PRÉSERVÉ (non supprimé/réaffecté), lien d'audit replaces_shift_id, pas de double-comptage,
 * fenêtre partielle, RBAC/scope.
 */
class WorkforceReplacementLot3Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private LeaveType $type;

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
        $this->company = Company::create(['name' => 'R3', 'slug' => 'r3-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->type = LeaveType::create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'Congés payés', 'is_paid' => true, 'enabled' => true]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $last): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => $last, 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);

        return $e->fresh();
    }

    private function publishedShift(Employee $e): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-11-02',
            'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 17:00:00', 'timezone' => 'Europe/Paris',
            'status' => Shift::STATUS_PUBLISHED, 'published_at' => now(), 'template_snapshot' => [],
        ]);
    }

    private function partialLeave(Employee $e): LeaveRequest
    {
        return LeaveRequest::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'leave_type_id' => $this->type->id,
            'date_from' => '2026-11-02', 'date_to' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '13:00',
            'status' => LeaveRequest::STATUS_APPROVED, 'days_count_hundredths' => 57,
        ]);
    }

    private function planner(): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish'])->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $user;
    }

    private function hdr(): array
    {
        return ['X-Company-Id' => $this->company->id];
    }

    public function test_candidates_exclude_absent_and_window_is_the_gap(): void
    {
        $a = $this->employee('Absent');
        $b = $this->employee('Backup');
        $shift = $this->publishedShift($a);
        $this->partialLeave($a);

        $res = $this->actingAs($this->planner())
            ->getJson("/api/workforce/planning/shifts/{$shift->id}/replacement-candidates", $this->hdr())
            ->assertOk();

        // Fenêtre non assurée = 09:00-13:00.
        $this->assertStringContainsString('09:00', $res->json('window.start'));
        $this->assertStringContainsString('13:00', $res->json('window.end'));
        $cands = collect($res->json('candidates'));
        // Le titulaire absent n'est pas « disponible » ; le backup l'est.
        $this->assertSame('available', $cands->firstWhere('employee_id', $b->id)['availability']);
        $absent = $cands->firstWhere('employee_id', $a->id);
        $this->assertSame('unavailable', $absent['availability']);
    }

    public function test_replace_creates_published_replacement_preserves_original_no_double_count(): void
    {
        $req = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 1, 'enabled' => true]);
        $a = $this->employee('Absent');
        $b = $this->employee('Backup');
        $shift = $this->publishedShift($a);
        $this->partialLeave($a);

        // Avant remplacement : 09:00-13:00 = 0/1 (absent exclu), 13:00-17:00 = 1/1.
        $before = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $morningBefore = collect($before['requirements'][0]['intervals'])->firstWhere('start', '09:00');
        $this->assertSame(0, $morningBefore['assigned']);

        $res = $this->actingAs($this->planner())->postJson(
            "/api/workforce/planning/shifts/{$shift->id}/replace",
            ['employee_id' => $b->id, 'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 13:00:00'],
            $this->hdr()
        )->assertStatus(201);

        $replacementId = $res->json('replacement.id');
        $replacement = Shift::withoutGlobalScopes()->find($replacementId);

        // Lien d'audit + publication + employé = backup.
        $this->assertSame($shift->id, (int) $replacement->replaces_shift_id);
        $this->assertSame(Shift::STATUS_PUBLISHED, $replacement->status);
        $this->assertSame($b->id, (int) $replacement->employee_id);

        // Shift initial PRÉSERVÉ (non supprimé, non réaffecté).
        $original = Shift::withoutGlobalScopes()->find($shift->id);
        $this->assertNotNull($original);
        $this->assertSame($a->id, (int) $original->employee_id);
        $this->assertSame(Shift::STATUS_PUBLISHED, $original->status);

        // Après : 09:00-13:00 = 1/1 (remplaçant, absent non compté → PAS 2), 13:00-17:00 = 1/1 (titulaire).
        $after = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $intervals = collect($after['requirements'][0]['intervals']);
        $this->assertSame(1, $intervals->firstWhere('start', '09:00')['assigned'], 'Remplaçant compte 1, absent exclu → pas de double-compte.');
        $this->assertSame(1, $intervals->firstWhere('start', '13:00')['assigned']);
    }

    public function test_replace_rejects_window_outside_original(): void
    {
        $a = $this->employee('Absent');
        $b = $this->employee('Backup');
        $shift = $this->publishedShift($a);

        $this->actingAs($this->planner())->postJson(
            "/api/workforce/planning/shifts/{$shift->id}/replace",
            ['employee_id' => $b->id, 'start_at' => '2026-11-02 08:00:00', 'end_at' => '2026-11-02 13:00:00'],
            $this->hdr()
        )->assertStatus(422);
    }
}
