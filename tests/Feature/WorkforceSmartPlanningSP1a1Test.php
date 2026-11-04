<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\CoverageReadModel;
use App\Modules\Workforce\ReadModels\ShiftReadModel;
use App\Modules\Workforce\Services\ScheduledActivityValidator;
use App\Modules\Workforce\UseCases\CopyWeekUseCase;
use App\Modules\Workforce\UseCases\CreateScheduledActivityUseCase;
use App\Modules\Workforce\UseCases\DuplicateShiftUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning SP1a.1 (ADR-708) — corrections de dette : pauses réelles, capacité en UNION (pas somme),
 * couverture par SOUS-INTERVALLES, scope de lecture des activités, copie de semaine / duplication (brouillons),
 * fix ShiftReadModel::forWeek (whereDate). Horloge figée.
 */
class WorkforceSmartPlanningSP1a1Test extends TestCase
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
        $this->company = Company::create(['name' => 'S11', 'slug' => 's11-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->deptA = Department::create(['company_id' => $this->company->id, 'name' => 'A']);
        $this->deptB = Department::create(['company_id' => $this->company->id, 'name' => 'B']);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(?Department $dept = null, bool $withContract = false): Employee
    {
        $e = Employee::create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01', 'department_id' => $dept?->id]);
        if ($withContract) {
            EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        }

        return $e;
    }

    private function shift(Employee $e, string $start, string $end, array $snapshot = [], string $status = Shift::STATUS_PUBLISHED): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => substr($start, 0, 10),
            'start_at' => $start, 'end_at' => $end, 'timezone' => 'Europe/Paris', 'status' => $status,
            'published_at' => $status === Shift::STATUS_PUBLISHED ? now() : null, 'template_snapshot' => $snapshot,
        ]);
    }

    private function act(Shift $shift, string $start, string $end, array $extra = []): array
    {
        return app(CreateScheduledActivityUseCase::class)->execute($this->company->id, $shift->id, array_merge(['start_at' => $start, 'end_at' => $end], $extra));
    }

    // ── §1A — pause réelle ──
    public function test_activity_overlapping_a_declared_break_is_rejected(): void
    {
        $shift = $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 18:00:00',
            ['break_windows' => [['start' => '13:00', 'end' => '14:00']]]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('planned break');
        $this->act($shift, '2026-11-02 12:30:00', '2026-11-02 13:30:00');
    }

    public function test_activity_outside_the_break_is_accepted(): void
    {
        $shift = $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 18:00:00',
            ['break_windows' => [['start' => '13:00', 'end' => '14:00']]]);
        $r = $this->act($shift, '2026-11-02 09:00:00', '2026-11-02 12:00:00');
        $this->assertNotNull($r['activity']->id);
    }

    // ── §1B — capacité en UNION (jamais somme de simultanées) ──
    public function test_union_capacity_does_not_double_count_simultaneous_non_exclusive(): void
    {
        // Shift 09:00–11:00 (120 min, pas de pause) → 120 travaillables.
        $shift = $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 11:00:00', [], Shift::STATUS_DRAFT);
        $this->act($shift, '2026-11-02 09:00:00', '2026-11-02 11:00:00', ['non_exclusive' => true]);
        // Deuxième activité simultanée non exclusive : union = 120 ≤ 120 → acceptée (la SOMME 240 refuserait).
        $r = $this->act($shift, '2026-11-02 09:00:00', '2026-11-02 11:00:00', ['non_exclusive' => true]);
        $this->assertNotNull($r['activity']->id);
    }

    // ── §2 — couverture par sous-intervalles ──
    public function test_coverage_subintervals_fully_covered_across_halves(): void
    {
        $req = \App\Core\Workforce\CoverageRequirement::create([
            'company_id' => $this->company->id, 'date' => '2026-11-02',
            'start_time' => '09:00', 'end_time' => '18:00', 'headcount' => 2, 'priority' => 'high', 'enabled' => true,
        ]);
        foreach ([['09:00', '13:00'], ['09:00', '13:00'], ['13:00', '18:00'], ['13:00', '18:00']] as [$a, $b]) {
            $this->shift($this->employee(), "2026-11-02 {$a}:00", "2026-11-02 {$b}:00");
        }
        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $row = collect($r['requirements'])->firstWhere('requirement_id', $req->id);

        $this->assertSame('covered', $row['status'], 'Deux demi-journées à 2/2 → aucun sous-effectif.');
        $this->assertSame(0, $row['gap']);
        $this->assertNotContains('under', collect($row['intervals'])->pluck('status')->all());
    }

    public function test_coverage_subintervals_detect_a_partial_gap(): void
    {
        $req = \App\Core\Workforce\CoverageRequirement::create([
            'company_id' => $this->company->id, 'date' => '2026-11-02',
            'start_time' => '09:00', 'end_time' => '18:00', 'headcount' => 2, 'enabled' => true,
        ]);
        // Seule la matinée est couverte (2), l'après-midi non (0).
        $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 13:00:00');
        $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 13:00:00');
        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $row = collect($r['requirements'])->firstWhere('requirement_id', $req->id);

        $this->assertSame('under', $row['status']);
        $this->assertContains('under', collect($row['intervals'])->pluck('status')->all());
    }

    // ── §1C — scope de lecture des activités ──
    private function user(array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    public function test_activities_index_is_scoped_not_company_wide_for_a_non_viewall_user(): void
    {
        $emp = $this->employee($this->deptA, true);
        $shift = $this->shift($emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', [], Shift::STATUS_DRAFT);
        $this->act($shift, '2026-11-02 09:00:00', '2026-11-02 10:00:00');
        $h = ['X-Company-Id' => $this->company->id];

        // Vue globale (publish) → voit l'activité.
        $full = $this->user(['workforce.planning_view', 'workforce.planning_publish']);
        $this->actingAs($full)->getJson("/api/workforce/planning/activities?shift_id={$shift->id}", $h)
            ->assertOk()->assertJsonCount(1);

        // Lecture seule sans vue globale et sans périmètre → ne récupère RIEN (pas de fuite Company-wide).
        $scoped = $this->user(['workforce.planning_view']);
        $this->actingAs($scoped)->getJson("/api/workforce/planning/activities?shift_id={$shift->id}", $h)
            ->assertOk()->assertJsonCount(0);
    }

    // ── §3 — copie de semaine / duplication (brouillons uniquement) ──
    public function test_copy_week_creates_drafts_and_preserves_source(): void
    {
        $emp = $this->employee(null, true);
        $published = $this->shift($emp, '2026-11-02 09:00:00', '2026-11-02 17:00:00', [], Shift::STATUS_PUBLISHED);

        $res = app(CopyWeekUseCase::class)->execute($this->company->id, '2026-11-02', '2026-11-09');
        $this->assertSame(1, $res['created']);

        // Source publiée intacte ; cible = brouillon la semaine suivante.
        $this->assertSame(Shift::STATUS_PUBLISHED, $published->fresh()->status);
        $copy = Shift::withoutGlobalScopes()->where('company_id', $this->company->id)->whereDate('date', '2026-11-09')->first();
        $this->assertNotNull($copy);
        $this->assertSame(Shift::STATUS_DRAFT, $copy->status, 'La copie est un brouillon, jamais publiée.');
    }

    public function test_duplicate_shift_creates_a_draft(): void
    {
        $emp = $this->employee(null, true);
        $shift = $this->shift($emp, '2026-11-02 09:00:00', '2026-11-02 17:00:00', [], Shift::STATUS_PUBLISHED);
        $new = app(DuplicateShiftUseCase::class)->execute($shift, '2026-11-03');
        $this->assertSame(Shift::STATUS_DRAFT, $new->status);
        $this->assertSame('2026-11-03', Carbon::parse($new->date)->toDateString());
    }

    // ── §7 — forWeek robuste (whereDate) ──
    public function test_forweek_returns_shift_created_in_the_week(): void
    {
        $emp = $this->employee();
        $this->shift($emp, '2026-11-04 09:00:00', '2026-11-04 17:00:00');
        $week = ShiftReadModel::forWeek($this->company->id, Carbon::parse('2026-11-02'));
        $this->assertCount(1, $week, 'Le shift du 04 est bien retourné pour la semaine du 02 (whereDate).');
    }
}
