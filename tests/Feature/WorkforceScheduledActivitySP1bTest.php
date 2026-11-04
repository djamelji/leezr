<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Modules\Workforce\UseCases\CreateScheduledActivityUseCase;
use App\Modules\Workforce\UseCases\UpdateScheduledActivityUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning SP1b (ADR-705 v2) — ScheduledActivity : multi-activités intra-shift.
 * Vérifie : bornes du shift, chevauchement (bloqué sauf non-exclusif), capacité (pause préservée),
 * passage de minuit, révision contrôlée d'un shift publié (sans muter shift ni TimeEntry), réaffectation,
 * suppression vs annulation selon l'état, permissions/scope/tenant, concurrence (verrou optimiste 409).
 */
class WorkforceScheduledActivitySP1bTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $emp;

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
        $this->company = Company::create(['name' => 'SA', 'slug' => 'sa-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->emp = $this->employee();
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'first_name' => 'A', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
    }

    private function shift(Employee $e, string $start, string $end, string $status = Shift::STATUS_PUBLISHED, int $break = 60): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => substr($start, 0, 10),
            'start_at' => $start, 'end_at' => $end, 'timezone' => 'Europe/Paris', 'status' => $status,
            'published_at' => $status === Shift::STATUS_PUBLISHED ? now() : null,
            'template_snapshot' => ['break_duration_minutes' => $break],
        ]);
    }

    private function create(Shift $shift, string $start, string $end, array $extra = []): array
    {
        return app(CreateScheduledActivityUseCase::class)->execute($this->company->id, $shift->id, array_merge(['start_at' => $start, 'end_at' => $end], $extra));
    }

    public function test_activity_created_within_shift_derives_employee(): void
    {
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00');
        $r = $this->create($shift, '2026-11-02 09:00:00', '2026-11-02 10:00:00', ['activity_type' => 'coupe']);

        $this->assertSame($this->emp->id, $r['activity']->employee_id, 'employee_id dérivé du shift.');
        $this->assertSame(60, $r['activity']->duration_minutes);
        $this->assertSame('coupe', $r['activity']->activity_type);
    }

    public function test_activity_outside_shift_bounds_is_rejected(): void
    {
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00');
        $this->expectException(\DomainException::class);
        $this->create($shift, '2026-11-02 08:00:00', '2026-11-02 09:30:00');
    }

    public function test_incompatible_overlap_is_rejected_but_non_exclusive_allowed(): void
    {
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00');
        $this->create($shift, '2026-11-02 09:00:00', '2026-11-02 10:00:00');

        try {
            $this->create($shift, '2026-11-02 09:30:00', '2026-11-02 10:30:00');
            $this->fail('Overlap should be rejected by default.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('overlaps', $e->getMessage());
        }

        // Non exclusif explicite → autorisé.
        $r = $this->create($shift, '2026-11-02 09:30:00', '2026-11-02 10:30:00', ['non_exclusive' => true]);
        $this->assertNotNull($r['activity']->id);
    }

    public function test_activities_cannot_exceed_shift_workable_time_break_preserved(): void
    {
        // Shift 09:00–18:00 (540) avec pause 60 → 480 travaillables.
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_DRAFT, 60);
        $this->create($shift, '2026-11-02 09:00:00', '2026-11-02 16:00:00'); // 420
        $this->expectException(\DomainException::class); // +120 = 540 > 480
        $this->create($shift, '2026-11-02 16:00:00', '2026-11-02 18:00:00');
    }

    public function test_activity_can_cross_midnight_within_night_shift(): void
    {
        $shift = $this->shift($this->emp, '2026-11-02 22:00:00', '2026-11-03 06:00:00', Shift::STATUS_PUBLISHED, 0);
        $r = $this->create($shift, '2026-11-02 23:00:00', '2026-11-03 01:00:00');
        $this->assertSame(120, $r['activity']->duration_minutes);
    }

    public function test_revision_on_published_shift_does_not_mutate_shift_or_timeentry(): void
    {
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_PUBLISHED);
        $entry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->emp->id, 'date' => '2026-11-02',
            'clock_in' => '2026-11-02 09:00:00', 'clock_out' => '2026-11-02 17:00:00',
            'status' => TimeEntry::STATUS_COMPLETED, 'total_worked_minutes' => 420, 'total_break_minutes' => 60, 'source' => 'manual',
        ]);
        $shiftUpdated = $shift->fresh()->updated_at;
        $entryWorked = $entry->fresh()->total_worked_minutes;

        // Ajout d'une prestation 15:00–16:00 sur le shift publié (révision contrôlée).
        $this->create($shift, '2026-11-02 15:00:00', '2026-11-02 16:00:00', ['activity_type' => 'prestation']);

        $this->assertSame(Shift::STATUS_PUBLISHED, $shift->fresh()->status);
        $this->assertEquals($shiftUpdated, $shift->fresh()->updated_at, 'Le shift publié n\'est pas muté.');
        $this->assertSame($entryWorked, $entry->fresh()->total_worked_minutes, 'Le TimeEntry (temps réel) est intact.');
    }

    public function test_reassign_to_another_shift_rederives_employee(): void
    {
        $emp2 = $this->employee();
        $shiftA = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_DRAFT);
        $shiftB = $this->shift($emp2, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_DRAFT);
        $act = $this->create($shiftA, '2026-11-02 09:00:00', '2026-11-02 10:00:00')['activity'];

        $r = app(UpdateScheduledActivityUseCase::class)->execute($act, ['shift_id' => $shiftB->id]);
        $this->assertSame($shiftB->id, $r['activity']->shift_id);
        $this->assertSame($emp2->id, $r['activity']->employee_id, 'employee_id re-dérivé du shift cible.');
    }

    // ── Endpoints : permissions / scope / concurrence ──

    private function user(array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    public function test_endpoints_enforce_permissions(): void
    {
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_DRAFT);
        $h = ['X-Company-Id' => $this->company->id];
        $payload = ['shift_id' => $shift->id, 'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 10:00:00'];

        $viewer = $this->user(['workforce.planning_view']);
        $this->actingAs($viewer)->getJson("/api/workforce/planning/activities?shift_id={$shift->id}", $h)->assertOk();
        $this->actingAs($viewer)->postJson('/api/workforce/planning/activities', $payload, $h)->assertForbidden();

        $manager = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $this->actingAs($manager)->postJson('/api/workforce/planning/activities', $payload, $h)
            ->assertCreated()->assertJsonStructure(['activity' => ['id', 'employee_id'], 'warnings']);
    }

    public function test_delete_only_on_draft_shift_else_cancel(): void
    {
        $manager = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $h = ['X-Company-Id' => $this->company->id];

        $published = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_PUBLISHED);
        $act = $this->create($published, '2026-11-02 09:00:00', '2026-11-02 10:00:00')['activity'];

        // Shift publié : suppression refusée → on annule.
        $this->actingAs($manager)->deleteJson("/api/workforce/planning/activities/{$act->id}", [], $h)->assertStatus(422);
        $this->actingAs($manager)->postJson("/api/workforce/planning/activities/{$act->id}/cancel", [], $h)->assertOk();
        $this->assertSame(ScheduledActivity::STATUS_CANCELLED, $act->fresh()->status);

        // Shift brouillon : suppression autorisée.
        $draft = $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_DRAFT);
        $act2 = $this->create($draft, '2026-11-02 09:00:00', '2026-11-02 10:00:00')['activity'];
        $this->actingAs($manager)->deleteJson("/api/workforce/planning/activities/{$act2->id}", [], $h)->assertOk();
        $this->assertDatabaseMissing('workforce_scheduled_activities', ['id' => $act2->id]);
    }

    public function test_optimistic_lock_on_update(): void
    {
        $manager = $this->user(['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish']);
        $h = ['X-Company-Id' => $this->company->id];
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_DRAFT);
        $act = $this->create($shift, '2026-11-02 09:00:00', '2026-11-02 10:00:00')['activity'];

        $this->actingAs($manager)->putJson("/api/workforce/planning/activities/{$act->id}", [
            'priority' => 'high', 'expected_updated_at' => '2020-01-01 00:00:00',
        ], $h)->assertStatus(409);
    }

    public function test_tenant_isolation_on_activity_access(): void
    {
        $shift = $this->shift($this->emp, '2026-11-02 09:00:00', '2026-11-02 18:00:00', Shift::STATUS_DRAFT);
        $act = $this->create($shift, '2026-11-02 09:00:00', '2026-11-02 10:00:00')['activity'];

        // Autre entreprise : ne doit pas accéder à l'activité par ID direct.
        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $other->id, 'module_key' => $m, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $other->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.planning_view', 'workforce.planning_manage'])->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $other->id, 'role' => 'member', 'company_role_id' => $role->id]);

        $this->actingAs($u)->putJson("/api/workforce/planning/activities/{$act->id}", ['priority' => 'high'], ['X-Company-Id' => $other->id])
            ->assertNotFound();
    }
}
