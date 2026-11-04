<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\CoverageReadModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning SP1a (ADR-705) — besoins de personnel (coverage) + modèles enrichis.
 * Vérifie : calcul de couverture (couvert/sous/sur-effectif), jamais de double-compte,
 * présence partielle ≠ complète, fenêtre horaire, portée Department ; enrichissement ScheduleTemplate
 * (code/icône/activité/department) + snapshot ; permissions des endpoints (serveur, pas seulement UI).
 */
class WorkforceCoverageSP1aTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $deptA;
    private Department $deptB;

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
        $this->company = Company::create(['name' => 'Cov', 'slug' => 'cov-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->deptA = Department::create(['company_id' => $this->company->id, 'name' => 'Dept A']);
        $this->deptB = Department::create(['company_id' => $this->company->id, 'name' => 'Dept B']);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(?Department $dept = null): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(),
            'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01',
            'department_id' => $dept?->id,
        ]);
    }

    private function shift(Employee $e, string $date, string $in, string $out, ?int $locationId = null, ?string $activityType = null): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date,
            'start_at' => "{$date} {$in}:00", 'end_at' => "{$date} {$out}:00",
            'timezone' => 'Europe/Paris', 'status' => Shift::STATUS_PUBLISHED, 'published_at' => now(),
            'work_location_id' => $locationId,
            'template_snapshot' => $activityType ? ['activity_type' => $activityType] : [],
        ]);
    }

    private function requirement(array $attrs): CoverageRequirement
    {
        return CoverageRequirement::create(array_merge([
            'company_id' => $this->company->id, 'headcount' => 1, 'priority' => 'normal', 'enabled' => true,
        ], $attrs));
    }

    private function rowFor(array $result, int $reqId, string $date): ?array
    {
        return collect($result['requirements'])->firstWhere(fn ($r) => $r['requirement_id'] === $reqId && $r['date'] === $date);
    }

    public function test_all_day_requirement_is_covered_by_any_shift(): void
    {
        $e = $this->employee($this->deptA);
        $this->shift($e, '2026-11-02', '09:00', '17:00');
        $req = $this->requirement(['date' => '2026-11-02', 'headcount' => 1]);

        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $row = $this->rowFor($r, $req->id, '2026-11-02');

        $this->assertSame(1, $row['assigned']);
        $this->assertSame('covered', $row['status']);
        $this->assertSame(0, $row['gap']);
    }

    public function test_partial_presence_is_not_counted_as_full_coverage(): void
    {
        $full = $this->employee($this->deptA);
        $partial = $this->employee($this->deptA);
        $this->shift($full, '2026-11-02', '08:00', '13:00');    // couvre 09:00-12:00
        $this->shift($partial, '2026-11-02', '10:00', '11:00');  // présence partielle
        $req = $this->requirement(['date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '12:00', 'headcount' => 2]);

        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $row = $this->rowFor($r, $req->id, '2026-11-02');

        $this->assertSame(1, $row['assigned'], 'Seul le shift couvrant intégralement la fenêtre compte.');
        $this->assertSame('under', $row['status']);
        $this->assertSame(1, $row['gap']);
    }

    public function test_same_employee_never_counted_twice_in_an_interval(): void
    {
        $e = $this->employee($this->deptA);
        $this->shift($e, '2026-11-02', '08:00', '12:00');
        $this->shift($e, '2026-11-02', '13:00', '18:00'); // même employé, 2 shifts couvrant la fenêtre
        $req = $this->requirement(['date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '10:00', 'headcount' => 1]);

        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $row = $this->rowFor($r, $req->id, '2026-11-02');

        $this->assertSame(1, $row['assigned'], 'DISTINCT employee_id — jamais compté deux fois.');
        $this->assertSame('covered', $row['status']);
    }

    public function test_overstaffing_is_reported(): void
    {
        $this->shift($this->employee($this->deptA), '2026-11-02', '09:00', '17:00');
        $this->shift($this->employee($this->deptA), '2026-11-02', '09:00', '17:00');
        $req = $this->requirement(['date' => '2026-11-02', 'headcount' => 1]);

        $row = $this->rowFor(CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02'), $req->id, '2026-11-02');

        $this->assertSame(2, $row['assigned']);
        $this->assertSame('over', $row['status']);
        $this->assertSame(-1, $row['gap']);
    }

    public function test_department_scope_filters_eligible_shifts(): void
    {
        $this->shift($this->employee($this->deptB), '2026-11-02', '09:00', '17:00'); // autre département
        $req = $this->requirement(['date' => '2026-11-02', 'department_id' => $this->deptA->id, 'headcount' => 1]);

        $row = $this->rowFor(CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02'), $req->id, '2026-11-02');

        $this->assertSame(0, $row['assigned'], 'Un shift hors du département scopé ne compte pas.');
        $this->assertSame('under', $row['status']);
    }

    public function test_recurring_requirement_applies_to_matching_weekday(): void
    {
        // 2026-11-02 = lundi (Carbon dayOfWeek 1). Récurrent day_of_week=1 doit s'appliquer ce jour-là.
        $this->shift($this->employee($this->deptA), '2026-11-02', '09:00', '17:00');
        $req = $this->requirement(['day_of_week' => 1, 'headcount' => 1]);

        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-08');
        $monday = $this->rowFor($r, $req->id, '2026-11-02');
        $tuesday = $this->rowFor($r, $req->id, '2026-11-03');

        $this->assertNotNull($monday);
        $this->assertSame('covered', $monday['status']);
        $this->assertNull($tuesday, 'Le besoin récurrent lundi ne génère pas de ligne les autres jours.');
    }

    public function test_template_enrichment_persists_and_snapshots(): void
    {
        $tpl = ScheduleTemplate::create([
            'company_id' => $this->company->id, 'name' => 'Route', 'code' => 'RT',
            'start_time' => '08:45', 'end_time' => '17:30', 'break_duration_minutes' => 45,
            'icon' => 'tabler-truck', 'activity_type' => 'tournee', 'department_id' => $this->deptA->id,
        ]);
        $snap = $tpl->fresh()->toSnapshot();

        $this->assertSame('RT', $tpl->fresh()->code);
        $this->assertSame('tournee', $snap['activity_type']);
        $this->assertSame('tabler-truck', $snap['icon']);
        $this->assertSame($this->deptA->id, $snap['department_id']);
    }

    // ── Endpoints : permissions serveur ──

    private function user(array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    public function test_coverage_endpoints_enforce_permissions(): void
    {
        $h = ['X-Company-Id' => $this->company->id];
        $payload = ['date' => '2026-11-02', 'headcount' => 2, 'priority' => 'high'];

        // Lecture seule (planning_view) : peut lire index/summary mais PAS créer.
        $viewer = $this->user(['workforce.planning_view']);
        $this->actingAs($viewer)->getJson('/api/workforce/planning/coverage', $h)->assertOk();
        $this->actingAs($viewer)->getJson('/api/workforce/planning/coverage/summary?from=2026-11-02&to=2026-11-08', $h)
            ->assertOk()->assertJsonStructure(['requirements', 'daily', 'range']);
        $this->actingAs($viewer)->postJson('/api/workforce/planning/coverage', $payload, $h)->assertForbidden();

        // Gestion (coverage_manage) : peut créer.
        $manager = $this->user(['workforce.planning_view', 'workforce.coverage_manage']);
        $this->actingAs($manager)->postJson('/api/workforce/planning/coverage', $payload, $h)->assertCreated();

        // Sans permission planning : 403.
        $none = $this->user(['workforce.employees_view']);
        $this->actingAs($none)->getJson('/api/workforce/planning/coverage', $h)->assertForbidden();
    }

    public function test_coverage_requires_date_or_weekday(): void
    {
        $manager = $this->user(['workforce.planning_view', 'workforce.coverage_manage']);
        $this->actingAs($manager)
            ->postJson('/api/workforce/planning/coverage', ['headcount' => 2], ['X-Company-Id' => $this->company->id])
            ->assertStatus(422);
    }
}
