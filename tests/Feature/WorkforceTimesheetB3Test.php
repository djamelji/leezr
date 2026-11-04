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
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E3-B / B3 (ADR-699) — feuilles de temps & validations :
 *  - projection d'historique scopée (workflow + corrections tracées, QUI/QUAND/QUOI/POURQUOI) ;
 *  - séparation des responsabilités RBAC (lecture ≠ validation) vérifiée au niveau ENDPOINT ;
 *  - note d'approbation requise seulement si anomalie de niveau erreur (aligné moteur) ;
 *  - LIMITE DOCUMENTÉE : la validation des feuilles est gated par PERMISSION mais n'est PAS scopée au
 *    périmètre manager côté serveur (company-wide) — contrairement aux pointages. Encodé ici pour qu'un
 *    futur changement de politique soit détecté.
 *
 * Horloge figée (leçon B2) : évite toute dépendance à la date courante.
 */
class WorkforceTimesheetB3Test extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['workforce', 'workforce_planning', 'workforce_leave', 'workforce_payroll'];
    private const START = '2026-11-02'; // lundi
    private const END = '2026-11-08';   // dimanche

    private Company $company;
    private Employee $manager;
    private Employee $report;
    private Employee $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 09:00:00'); // après la période → déterministe
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => self::MODULES]);
        Market::create(['key' => 'FR', 'name' => 'FR', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (self::MODULES as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'TS B3', 'slug' => 'ts-b3', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->manager = $this->emp('Mona', null);
        $this->report = $this->emp('Remy', $this->manager->id);
        $this->stranger = $this->emp('Sam', null);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emp(string $f, ?int $mgr): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => $f, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'employee_number' => 'E-'.uniqid()]);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);

        return $e;
    }

    private function user(array $perms, ?Employee $asEmployee = null): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
        if ($asEmployee) {
            $asEmployee->update(['user_id' => $u->id]);
        }

        return $u;
    }

    private function entry(Employee $e, string $date): TimeEntry
    {
        return TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date,
            'clock_in' => "{$date} 09:00:00", 'clock_out' => "{$date} 17:00:00",
            'status' => 'completed', 'total_worked_minutes' => 420, 'total_break_minutes' => 60, 'source' => 'manual',
        ]);
    }

    private function h(User $u): array
    {
        return ['X-Company-Id' => $this->company->id];
    }

    private function fullUser(): User
    {
        return $this->user(['workforce.timesheet_view', 'workforce.timesheet_submit', 'workforce.timesheet_approve', 'workforce.time_manage', 'workforce.time_approve']);
    }

    // ── Historique : workflow + correction tracée ──

    public function test_history_projects_workflow_and_correction(): void
    {
        $u = $this->fullUser();
        $e1 = $this->entry($this->report, '2026-11-03');
        $this->entry($this->report, '2026-11-04');

        // Correction tracée AVANT génération (motif obligatoire) → écrit time_entry.updated.
        $this->actingAs($u)->putJson("/api/workforce/time-entries/{$e1->id}", ['clock_out' => '2026-11-03 17:30:00', 'reason' => 'Oubli de sortie'], $this->h($u))->assertOk();

        // Génère → soumet → approuve (écrit timesheet.generated/submitted/approved).
        $gen = $this->actingAs($u)->postJson('/api/workforce/timesheets', ['employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END], $this->h($u))->assertStatus(201)->json();
        $pid = $gen['id'];
        $this->actingAs($u)->postJson("/api/workforce/timesheets/{$pid}/submit", [], $this->h($u))->assertOk();
        $this->actingAs($u)->postJson("/api/workforce/timesheets/{$pid}/approve", ['approval_note' => 'OK'], $this->h($u))->assertOk();

        $events = $this->actingAs($u)->getJson("/api/workforce/timesheets/{$pid}/history", $this->h($u))->assertOk()->json('events');
        $actions = collect($events)->pluck('action')->all();

        $this->assertContains('timesheet.generated', $actions);
        $this->assertContains('timesheet.submitted', $actions);
        $this->assertContains('timesheet.approved', $actions);
        $correction = collect($events)->firstWhere('action', 'time_entry.updated');
        $this->assertNotNull($correction, 'La correction tracée doit figurer dans l\'historique.');
        $this->assertSame('Oubli de sortie', $correction['reason']);
        $this->assertSame('correction', $correction['kind']);
        $this->assertNotNull($correction['actor']); // QUI
    }

    public function test_history_requires_view_permission(): void
    {
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'draft']);

        $noView = $this->user(['workforce.planning_view']); // pas timesheet_view
        $this->actingAs($noView)->getJson("/api/workforce/timesheets/{$period->id}/history", $this->h($noView))->assertForbidden();
    }

    public function test_history_tenant_isolation(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-b3', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $otherPeriod = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $other->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'draft']);

        $u = $this->fullUser();
        $this->actingAs($u)->getJson("/api/workforce/timesheets/{$otherPeriod->id}/history", $this->h($u))->assertNotFound();
    }

    // ── Séparation RBAC : lecture ≠ validation (au niveau endpoint, pas que les boutons) ──

    public function test_view_permission_cannot_approve(): void
    {
        // Lecteur dans son périmètre (manager du collaborateur) : peut LIRE mais pas VALIDER.
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'submitted']);
        $viewer = $this->user(['workforce.timesheet_view'], $this->manager); // lecture seule, périmètre = soi + rapports

        $this->actingAs($viewer)->getJson("/api/workforce/timesheets/{$period->id}", $this->h($viewer))->assertOk();
        $this->actingAs($viewer)->postJson("/api/workforce/timesheets/{$period->id}/approve", ['approval_note' => 'x'], $this->h($viewer))->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/workforce/timesheets/{$period->id}/submit", [], $this->h($viewer))->assertForbidden();
    }

    // ── Périmètre (scope) appliqué à TOUTES les opérations (pas que approve), domaine 'time' ──

    public function test_read_and_history_scoped_for_non_view_all(): void
    {
        // Manager lecteur (timesheet_view, PAS view-all) : périmètre = soi + rapports directs.
        $viewer = $this->user(['workforce.timesheet_view'], $this->manager);
        $reportP = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'approved']);
        $strangerP = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'approved']);

        // Dans le périmètre → 200 ; hors périmètre → 403 (lecture ET historique).
        $this->actingAs($viewer)->getJson("/api/workforce/timesheets/{$reportP->id}", $this->h($viewer))->assertOk();
        $this->actingAs($viewer)->getJson("/api/workforce/timesheets/{$reportP->id}/history", $this->h($viewer))->assertOk();
        $this->actingAs($viewer)->getJson("/api/workforce/timesheets/{$strangerP->id}", $this->h($viewer))->assertForbidden();
        $this->actingAs($viewer)->getJson("/api/workforce/timesheets/{$strangerP->id}/history", $this->h($viewer))->assertForbidden();
    }

    public function test_list_scoped_for_non_view_all(): void
    {
        $viewer = $this->user(['workforce.timesheet_view'], $this->manager);
        $reportP = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'approved']);
        TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'approved']);

        $ids = collect($this->actingAs($viewer)->getJson('/api/workforce/timesheets', $this->h($viewer))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($reportP->id, $ids);
        $this->assertCount(1, $ids, 'La liste ne doit contenir que les feuilles du périmètre (rapports directs).');
    }

    public function test_write_scoped_for_non_view_all(): void
    {
        // Un utilisateur pouvant soumettre mais SANS view-all ne peut agir que dans son périmètre.
        $submitter = $this->user(['workforce.timesheet_view', 'workforce.timesheet_submit'], $this->manager);
        $strangerP = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'draft']);

        // Hors périmètre → 403 (la garde de périmètre précède toute logique métier).
        $this->actingAs($submitter)->postJson("/api/workforce/timesheets/{$strangerP->id}/submit", [], $this->h($submitter))->assertForbidden();
    }

    // ── Concurrence : re-check d'état sous verrou, pas de double audit ──

    public function test_transition_rechecks_state_no_double_audit(): void
    {
        $u = $this->fullUser();
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'submitted']);
        TimesheetLine::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'timesheet_period_id' => $period->id, 'employee_id' => $this->report->id, 'date' => '2026-11-03', 'worked_minutes' => 420, 'break_minutes' => 60, 'planned_minutes' => 420, 'anomalies' => [], 'source_snapshot' => []]);

        // 1ʳᵉ approbation OK ; 2ᵉ (état re-lu sous verrou = déjà approved) → 422. Un seul événement d'audit.
        $this->actingAs($u)->postJson("/api/workforce/timesheets/{$period->id}/approve", ['approval_note' => 'ok'], $this->h($u))->assertOk();
        $this->actingAs($u)->postJson("/api/workforce/timesheets/{$period->id}/approve", ['approval_note' => 'ok'], $this->h($u))->assertStatus(422);

        $count = \App\Core\Audit\CompanyAuditLog::withoutGlobalScopes()
            ->where('action', 'timesheet.approved')->where('target_id', (string) $period->id)->count();
        $this->assertSame(1, $count, 'Une seule transition approuvée doit être journalisée (pas de double audit).');
    }

    // ── Note d'approbation requise seulement si anomalie de niveau erreur (aligné moteur) ──

    public function test_approve_requires_note_only_when_error_anomaly(): void
    {
        $u = $this->fullUser();
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'submitted']);
        TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'timesheet_period_id' => $period->id, 'employee_id' => $this->report->id,
            'date' => '2026-11-03', 'worked_minutes' => 420, 'break_minutes' => 60, 'planned_minutes' => 420,
            'anomalies' => [['type' => 'leave_conflict', 'severity' => 'error', 'details' => 'Congé et pointage le même jour']],
            'source_snapshot' => [],
        ]);

        // Sans note → 422 ; avec note → 200.
        $this->actingAs($u)->postJson("/api/workforce/timesheets/{$period->id}/approve", [], $this->h($u))->assertStatus(422);
        $this->actingAs($u)->postJson("/api/workforce/timesheets/{$period->id}/approve", ['approval_note' => 'Anomalie acquittée'], $this->h($u))->assertOk();
    }

    // ── ARBITRAGE RBAC : timesheet_approve_team (équipe) vs timesheet_approve (Company) ──

    private function submittedFor(Employee $e): TimesheetPeriod
    {
        return TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'submitted']);
    }

    private function approve(User $u, int $pid)
    {
        return $this->actingAs($u)->postJson("/api/workforce/timesheets/{$pid}/approve", ['approval_note' => 'ok'], $this->h($u));
    }

    public function test_team_approver_can_approve_direct_report(): void
    {
        $mgr = $this->user(['workforce.timesheet_approve_team'], $this->manager);
        $this->approve($mgr, $this->submittedFor($this->report)->id)->assertOk();
    }

    public function test_team_approver_cannot_approve_outside_perimeter(): void
    {
        $mgr = $this->user(['workforce.timesheet_approve_team'], $this->manager);
        $this->approve($mgr, $this->submittedFor($this->stranger)->id)->assertForbidden();
    }

    public function test_team_approver_cannot_self_approve(): void
    {
        // Interdiction d'auto-approbation via la SEULE permission d'équipe (§2 décision produit).
        $mgr = $this->user(['workforce.timesheet_approve_team'], $this->manager);
        $own = $this->submittedFor($this->manager);
        $this->approve($mgr, $own->id)->assertForbidden();
        $this->actingAs($mgr)->postJson("/api/workforce/timesheets/{$own->id}/reject", ['rejection_reason' => 'x'], $this->h($mgr))->assertForbidden();
    }

    public function test_company_approver_can_approve_anyone(): void
    {
        $rh = $this->user(['workforce.timesheet_approve'], $this->manager);
        $this->approve($rh, $this->submittedFor($this->stranger)->id)->assertOk();
    }

    public function test_both_permissions_company_scope_includes_self(): void
    {
        // Titulaire des DEUX : périmètre Company (incl. soi) — comportement existant préservé (risque documenté).
        $u = $this->user(['workforce.timesheet_approve', 'workforce.timesheet_approve_team'], $this->manager);
        $this->approve($u, $this->submittedFor($this->manager)->id)->assertOk();
    }

    public function test_view_only_cannot_reach_approve_route(): void
    {
        // Droit d'action absent (ni approve ni approve_team) → 403 au middleware use-any-permission.
        $viewer = $this->user(['workforce.timesheet_view'], $this->manager);
        $this->approve($viewer, $this->submittedFor($this->report)->id)->assertForbidden();
    }

    public function test_unique_identity_constraint_blocks_duplicate_period(): void
    {
        // Garde-fou concurrence DB : deux feuilles de MÊME identité métier sont refusées par la contrainte.
        TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'draft']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'draft']);
    }

    // ── Périmètre des approbateurs = Company (comportement INTENTIONnel du modèle existant) ──

    public function test_approver_with_timesheet_approve_has_company_scope(): void
    {
        // Comportement VOULU (pas une permissivité résiduelle) : `timesheet_approve` accorde
        // `hasViewAll('time')` → périmètre Company. Un approbateur peut donc approuver toute feuille de
        // la société (y compris hors de ses rapports directs). C'est explicitement souhaité (« ne pas
        // limiter arbitrairement les titulaires de timesheet_approve à leur équipe »). Permission ET
        // périmètre ET état sont vérifiés serveur (ici le périmètre = Company via view-all).
        // ARBITRAGE OUVERT (non implémenté) : une permission « approuver uniquement mon équipe » serait
        // une nouvelle clé RBAC → décision produit. (ADR-699 / B3-checkpoint)
        $mgr = $this->user(['workforce.timesheet_approve'], $this->manager);
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'submitted']);

        $this->actingAs($mgr)->postJson("/api/workforce/timesheets/{$period->id}/approve", ['approval_note' => 'x'], $this->h($mgr))->assertOk();
    }
}
