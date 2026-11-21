<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Core\Workforce\Employee;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimeEntryBreak;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Core\Workforce\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-844 (Leezr 4.2 M7, FUN-06) — time / planning / absence exports. Expected values are written BY HAND from the
 * fixture below (Europe/Paris = UTC+1 in March), never computed by the code under test.
 */
class Adr844WorkforceTimeExportTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    private Employee $alice;   // team of the manager

    private Employee $bruno;   // not in the manager's team

    private Employee $manager;

    private User $managerUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        MarketRegistry::sync();
        PlanRegistry::sync();
        FieldDefinitionCatalog::sync();
        $modules = ['workforce', 'workforce_planning', 'workforce_leave'];
        Jobdomain::where('key', 'logistique')->update(['default_modules' => $modules]);

        $this->company = Company::create(['name' => 'Export Co', 'slug' => 'export-co', 'plan_key' => 'business', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->activateCompanyModules($this->company);
        foreach ($modules as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        $this->managerUser = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'lead', 'name' => 'Lead', 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.export', 'workforce.timesheet_view', 'workforce.planning_view'])->pluck('id')->all());
        $this->company->memberships()->create(['user_id' => $this->managerUser->id, 'role' => 'user', 'company_role_id' => $role->id]);

        $mk = fn (string $first, string $num, ?int $managerId = null, ?int $userId = null) => Employee::create(['company_id' => $this->company->id, 'first_name' => $first, 'last_name' => 'Test', 'employee_number' => $num, 'status' => 'active', 'manager_id' => $managerId, 'user_id' => $userId, 'hire_date' => '2026-01-01']);
        $this->manager = $mk('Manon', 'M-1', null, $this->managerUser->id);
        $this->alice = $mk('Alice', 'A-1', $this->manager->id);
        $this->bruno = $mk('Bruno', 'B-1');

        // clock-ins (UTC) : Alice 08:00 → 16:30 with a 30 min break ; Bruno a day outside the period ; an open clock-in for Bruno
        $e = TimeEntry::create(['company_id' => $this->company->id, 'employee_id' => $this->alice->id, 'date' => '2026-03-10', 'clock_in' => '2026-03-10 08:00:00', 'clock_out' => '2026-03-10 16:30:00', 'status' => 'completed', 'total_worked_minutes' => 480, 'total_break_minutes' => 30, 'source' => 'web']);
        TimeEntryBreak::create(['company_id' => $this->company->id, 'time_entry_id' => $e->id, 'start_at' => '2026-03-10 12:00:00', 'end_at' => '2026-03-10 12:30:00', 'type' => 'lunch', 'duration_minutes' => 30]);
        TimeEntry::create(['company_id' => $this->company->id, 'employee_id' => $this->bruno->id, 'date' => '2026-02-01', 'clock_in' => '2026-02-01 08:00:00', 'clock_out' => '2026-02-01 12:00:00', 'status' => 'completed', 'total_worked_minutes' => 240, 'total_break_minutes' => 0, 'source' => 'web']);
        TimeEntry::create(['company_id' => $this->company->id, 'employee_id' => $this->bruno->id, 'date' => '2026-03-11', 'clock_in' => '2026-03-11 07:00:00', 'status' => 'working', 'total_worked_minutes' => 0, 'total_break_minutes' => 0, 'source' => 'web']);

        // timesheets : Alice approved (planned 450, worked 480, overtime 30) ; Bruno submitted (not validated)
        $pa = TimesheetPeriod::create(['company_id' => $this->company->id, 'employee_id' => $this->alice->id, 'period_start' => '2026-03-09', 'period_end' => '2026-03-15', 'status' => 'approved']);
        TimesheetLine::create(['company_id' => $this->company->id, 'timesheet_period_id' => $pa->id, 'employee_id' => $this->alice->id, 'date' => '2026-03-10', 'worked_minutes' => 480, 'break_minutes' => 30, 'daily_overtime_minutes' => 30, 'planned_minutes' => 450, 'leave_minutes' => 0, 'is_leave_day' => false, 'is_rest_day' => false, 'source_snapshot' => []]);
        $pb = TimesheetPeriod::create(['company_id' => $this->company->id, 'employee_id' => $this->bruno->id, 'period_start' => '2026-03-09', 'period_end' => '2026-03-15', 'status' => 'submitted']);
        TimesheetLine::create(['company_id' => $this->company->id, 'timesheet_period_id' => $pb->id, 'employee_id' => $this->bruno->id, 'date' => '2026-03-11', 'worked_minutes' => 0, 'break_minutes' => 0, 'daily_overtime_minutes' => 0, 'planned_minutes' => 420, 'leave_minutes' => 420, 'leave_type_code' => 'CP', 'is_leave_day' => true, 'is_rest_day' => false, 'source_snapshot' => []]);

        // planning : Alice 09:00–16:30 Paris at the depot
        $site = WorkLocation::create(['company_id' => $this->company->id, 'name' => 'Dépôt Nord', 'type' => 'internal', 'enabled' => true]);
        Shift::create(['company_id' => $this->company->id, 'employee_id' => $this->alice->id, 'work_location_id' => $site->id, 'date' => '2026-03-10', 'start_at' => '2026-03-10 08:00:00', 'end_at' => '2026-03-10 15:30:00', 'timezone' => 'Europe/Paris', 'status' => 'published']);

        // leaves : Bruno approved 2,5 days ; Alice pending 1 day ; Alice draft (never exported)
        $cp = LeaveType::create(['company_id' => $this->company->id, 'code' => 'CP', 'name' => 'Congés payés', 'accrual_mode' => 'none', 'enabled' => true]);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $this->bruno->id, 'leave_type_id' => $cp->id, 'date_from' => '2026-03-11', 'date_to' => '2026-03-13', 'days_count_hundredths' => 250, 'status' => 'approved']);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $this->alice->id, 'leave_type_id' => $cp->id, 'date_from' => '2026-03-20', 'date_to' => '2026-03-20', 'days_count_hundredths' => 100, 'status' => 'pending']);
        LeaveRequest::create(['company_id' => $this->company->id, 'employee_id' => $this->alice->id, 'leave_type_id' => $cp->id, 'date_from' => '2026-03-25', 'date_to' => '2026-03-25', 'days_count_hundredths' => 100, 'status' => 'draft']);
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id, 'X-Locale' => 'fr']); // headers follow the interface language
    }

    private function csv(User $u, string $dataset, array $extra = []): array
    {
        $r = $this->as($u)->get('/api/workforce/exports/time/download?'.http_build_query(['from' => '2026-03-01', 'to' => '2026-03-31', 'dataset' => $dataset] + $extra));
        $r->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $r->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'UTF-8 BOM');

        return array_map(fn ($l) => str_getcsv($l, ';'), array_values(array_filter(explode("\n", substr($body, 3)))));
    }

    public function test_entries_in_company_timezone_and_period_filter(): void
    {
        $rows = $this->csv($this->owner, 'entries');
        $this->assertSame(['Date', 'Matricule', 'Nom', 'Prénom', 'Service', 'Arrivée', 'Départ', 'Pauses (min)', 'Travaillé (min)', 'Travaillé (hh:mm)', 'Statut', 'Origine'], $rows[0]);
        $this->assertCount(3, $rows, 'header + Alice + open Bruno ; February excluded');
        $this->assertSame(['2026-03-10', 'A-1', 'Test', 'Alice', '', '2026-03-10 09:00', '2026-03-10 17:30', '30', '480', '08:00', 'terminé', 'web'], $rows[1]);
        $this->assertSame('', $rows[2][6], 'an open clock-in has no clock-out');
    }

    public function test_breaks_daily_planned_vs_worked_validation_and_overtime(): void
    {
        $this->assertSame(['2026-03-10', 'A-1', 'Test', 'Alice', '2026-03-10 13:00', '2026-03-10 13:30', 'repas', '30'], $this->csv($this->owner, 'breaks')[1]);

        $daily = $this->csv($this->owner, 'daily');
        $alice = collect($daily)->firstWhere(1, 'A-1');
        // planned 450 · worked 480 · +30 · break 30 · overtime 30 · validated
        $this->assertSame(['450', '480', '30', '30', '30', '0', '', 'non', '0', 'approuvée', 'oui'], array_slice($alice, 4));
        $bruno = collect($daily)->firstWhere(1, 'B-1');
        $this->assertSame(['420', '0', '-420'], array_slice($bruno, 4, 3));
        $this->assertSame(['420', 'CP'], array_slice($bruno, 9, 2));
        $this->assertSame(['soumise', 'non'], array_slice($bruno, 13, 2), 'submitted ≠ validated');

        $preview = $this->as($this->owner)->getJson('/api/workforce/exports/time/preview?'.http_build_query(['from' => '2026-03-01', 'to' => '2026-03-31', 'datasets' => ['daily', 'leaves', 'entries']]))->assertOk();
        $this->assertContains('unvalidated:1', $preview->json('datasets.daily.warnings'));
        $this->assertContains('pending_leaves', $preview->json('datasets.leaves.warnings'));
        $this->assertContains('open_entries', $preview->json('datasets.entries.warnings'));
    }

    public function test_planning_with_site_and_leaves_with_statuses(): void
    {
        $planning = $this->csv($this->owner, 'planning');
        $this->assertSame(['2026-03-10', 'A-1', 'Test', 'Alice', 'Dépôt Nord', '2026-03-10 09:00', '2026-03-10 16:30', 'Europe/Paris', '450', 'publié'], $planning[1]);
        $other = WorkLocation::create(['company_id' => $this->company->id, 'name' => 'Autre', 'type' => 'internal', 'enabled' => true]);
        $this->assertCount(1, $this->csv($this->owner, 'planning', ['work_location_id' => $other->id]), 'site filter : header only');

        $leaves = $this->csv($this->owner, 'leaves');
        $this->assertCount(3, $leaves, 'approved + pending ; the draft is never exported');
        $this->assertSame(['B-1', 'Test', 'Bruno', 'Congés payés', '2026-03-11', '2026-03-13', '', '', '2,5', '', 'approuvée', ''], $leaves[1]);
        $this->assertSame('en attente', $leaves[2][10]);
    }

    public function test_one_person_filter_and_manager_scope(): void
    {
        $this->assertCount(2, $this->csv($this->owner, 'entries', ['employee_ids' => [$this->alice->id]]));
        // the manager (no « see everyone » in the time domain) only gets self + direct reports, even when asking for Bruno
        $this->assertSame(['A-1'], array_column(array_slice($this->csv($this->managerUser, 'entries'), 1), 1));
        $this->assertCount(1, $this->csv($this->managerUser, 'entries', ['employee_ids' => [$this->bruno->id]]));
    }

    public function test_permission_tenant_isolation_and_audit_without_content(): void
    {
        $member = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'nope', 'name' => 'No export', 'is_administrative' => false]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.timesheet_view'])->pluck('id')->all());
        $this->company->memberships()->create(['user_id' => $member->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $this->as($member)->get('/api/workforce/exports/time/download?from=2026-03-01&to=2026-03-31&dataset=entries')->assertForbidden();

        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'plan_key' => 'business', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $other->id])->get('/api/workforce/exports/time/download?from=2026-03-01&to=2026-03-31&dataset=entries')->assertForbidden();
        $otherEmployee = Employee::create(['company_id' => $other->id, 'first_name' => 'Zoé', 'last_name' => 'Ailleurs', 'employee_number' => 'Z-9', 'status' => 'active', 'hire_date' => '2026-01-01']);
        TimeEntry::create(['company_id' => $other->id, 'employee_id' => $otherEmployee->id, 'date' => '2026-03-10', 'clock_in' => '2026-03-10 08:00:00', 'clock_out' => '2026-03-10 09:00:00', 'status' => 'completed', 'total_worked_minutes' => 60, 'total_break_minutes' => 0]);
        $this->assertCount(1, $this->csv($this->owner, 'entries', ['employee_ids' => [$otherEmployee->id]]), 'another company\'s employee id yields nothing');

        $this->csv($this->owner, 'leaves');
        $log = CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'workforce.time_exported')->latest('id')->first();
        $this->assertSame('leaves', $log->metadata['dataset']);
        $this->assertSame(2, $log->metadata['rows']);
        $this->assertStringNotContainsString('Bruno', json_encode($log->toArray()), 'the audit never holds exported content');
    }

    public function test_bounds_and_no_sensitive_columns(): void
    {
        $this->as($this->owner)->getJson('/api/workforce/exports/time/preview?from=2025-01-01&to=2026-03-31&datasets[]=entries')->assertUnprocessable();
        $this->as($this->owner)->getJson('/api/workforce/exports/time/preview?from=2026-03-31&to=2026-03-01&datasets[]=entries')->assertUnprocessable();
        $this->as($this->owner)->getJson('/api/workforce/exports/time/preview?from=2026-03-01&to=2026-03-31&datasets[]=payroll')->assertUnprocessable();
        $all = json_encode(\App\Modules\Workforce\Exports\TimeExportService::COLUMNS);
        foreach (['email', 'phone', 'iban', 'nir', 'social', 'salary', 'birth'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $all);
        }
    }
}
