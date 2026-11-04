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
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->report->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'submitted']);
        $viewer = $this->user(['workforce.timesheet_view']); // lecture seule

        $this->actingAs($viewer)->getJson("/api/workforce/timesheets/{$period->id}", $this->h($viewer))->assertOk();
        $this->actingAs($viewer)->postJson("/api/workforce/timesheets/{$period->id}/approve", ['approval_note' => 'x'], $this->h($viewer))->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/workforce/timesheets/{$period->id}/submit", [], $this->h($viewer))->assertForbidden();
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

    // ── LIMITE DOCUMENTÉE : validation company-wide (non scopée au manager) ──

    public function test_timesheet_approval_is_company_wide_not_manager_scoped(): void
    {
        // Un manager avec timesheet_approve peut approuver la feuille d'un collaborateur HORS de son
        // périmètre (le « stranger »). C'est le comportement SERVEUR actuel : les timesheets sont gated
        // par permission mais PAS scopés au manager (contrairement aux pointages). Ce test encode cette
        // limite connue ; toute évolution de politique (scoper) le fera échouer volontairement. (ADR-699)
        $mgr = $this->user(['workforce.timesheet_approve'], $this->manager);
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->stranger->id, 'period_start' => self::START, 'period_end' => self::END, 'status' => 'submitted']);

        $this->actingAs($mgr)->postJson("/api/workforce/timesheets/{$period->id}/approve", ['approval_note' => 'x'], $this->h($mgr))->assertOk();
    }
}
