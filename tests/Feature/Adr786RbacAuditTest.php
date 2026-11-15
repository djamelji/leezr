<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\FeatureFlag\FeatureFlag;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-786 — RBAC audit : the field worker (« Bobo », Driver role as found in DEV), personal vs collective
 * payroll, live time entries scope, people file access facet, company-scoped feature flags, tenant.
 * Direct HTTP calls : what the UI hides is irrelevant here.
 */
class Adr786RbacAuditTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave', 'workforce_payroll'];

    /** Driver role exactly as observed in DEV company 1 (legacy role : preset + members.invite). */
    private const DRIVER = ['documents.view', 'members.invite', 'members.view', 'settings.view', 'shipments.manage_status', 'shipments.view_own', 'theme.manage', 'theme.view'];

    private Company $a;
    private Company $b;
    private User $owner;
    private User $driver;
    private User $colleague;
    private Employee $driverEmp;
    private Employee $colleagueEmp;
    private Membership $colleagueMembership;
    private CompanyRole $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();
        Storage::fake('local');
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => self::MODULES]);

        $this->a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        foreach ([$this->a, $this->b] as $c) {
            $this->activateCompanyModules($c);
            foreach (self::MODULES as $m) {
                \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
            }
        }
        $this->adminRole = $this->setUpCompanyRbac($this->a);

        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->a->id, 'role' => 'owner', 'company_role_id' => $this->adminRole->id]);

        $this->driver = $this->member($this->role('driver', self::DRIVER));
        $this->colleague = $this->member($this->adminRole);
        $this->colleagueMembership = Membership::where('user_id', $this->colleague->id)->firstOrFail();
        $this->driverEmp = $this->employee(['user_id' => $this->driver->id, 'first_name' => 'Bobo']);
        $this->colleagueEmp = $this->employee(['user_id' => $this->colleague->id, 'first_name' => 'Alice']);
    }

    private function role(string $key, array $permissions, bool $administrative = false): CompanyRole
    {
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => $key.'-'.uniqid(), 'name' => $key, 'is_administrative' => $administrative]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());

        return $role;
    }

    private function member(?CompanyRole $role): User
    {
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $this->a->id, 'role' => 'user', 'company_role_id' => $role?->id]);

        return $u;
    }

    private function employee(array $attrs): Employee
    {
        return Employee::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->a->id, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ], $attrs));
    }

    private function as(User $u, string $method, string $uri, array $body = [], ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->json($method, '/api/'.$uri, $body);
    }

    private function payslip(Employee $e, string $period): PublishedPayslip
    {
        Storage::disk('local')->put("payslips/{$e->id}-{$period}.pdf", '%PDF-1.4 test');

        return PublishedPayslip::create(['company_id' => $this->a->id, 'document_subject_type' => 'employee', 'document_subject_id' => $e->id,
            'period' => $period, 'file_path' => "payslips/{$e->id}-{$period}.pdf", 'file_name' => "{$period}.pdf", 'file_sha256' => str_repeat('a', 64),
            'file_size_bytes' => 13, 'source' => 'import', 'version_no' => 1, 'created_at' => now()]);
    }

    // ── « Voir la paie » : personnel vs collectif ─────────────────────────────

    public function test_driver_reads_and_downloads_only_his_own_payslips(): void
    {
        $mine = $this->payslip($this->driverEmp, '2026-09');
        $theirs = $this->payslip($this->colleagueEmp, '2026-09');

        $list = $this->as($this->driver, 'GET', 'profile/payslips')->assertOk()->json('payslips');
        $this->assertSame([$mine->id], array_column($list, 'id'));
        $this->as($this->driver, 'GET', "profile/payslips/{$mine->id}/download")->assertOk();
        $this->as($this->driver, 'GET', "profile/payslips/{$theirs->id}/download")->assertNotFound();
    }

    public function test_driver_never_reaches_collective_payroll_nor_colleague_payslips(): void
    {
        $theirs = $this->payslip($this->colleagueEmp, '2026-09');
        $ref = 'employee-'.$this->colleagueEmp->id;

        $this->as($this->driver, 'GET', 'workforce/payroll')->assertForbidden();
        $this->as($this->driver, 'POST', 'workforce/payroll', ['period_start' => '2026-10-01', 'period_end' => '2026-10-31'])->assertForbidden();
        $this->as($this->driver, 'GET', 'workforce/dsn')->assertForbidden();
        $res = $this->as($this->driver, 'GET', "company/people/{$ref}/payslips");
        $this->assertContains($res->status(), [403, 404]);
        $this->assertStringNotContainsString('2026-09', $res->getContent());
        $this->assertContains($this->as($this->driver, 'GET', "company/people/{$ref}/payslips/{$theirs->id}/download")->status(), [403, 404]);
    }

    public function test_driver_has_no_hr_record_access_on_a_colleague(): void
    {
        $id = $this->colleagueEmp->id;
        $this->as($this->driver, 'GET', "workforce/employees/{$id}")->assertForbidden();
        $this->as($this->driver, 'GET', "workforce/employees/{$id}/file")->assertForbidden();
        $this->as($this->driver, 'GET', "workforce/employees/{$id}/contracts")->assertForbidden();
        $this->as($this->driver, 'GET', "workforce/employees/{$id}/leave-balances")->assertForbidden();
        $this->as($this->driver, 'GET', 'workforce/employees')->assertForbidden();
    }

    public function test_driver_cannot_administer_members_roles_settings_or_billing(): void
    {
        $this->as($this->driver, 'PUT', "company/members/{$this->colleagueMembership->id}", ['company_role_id' => $this->adminRole->id])->assertForbidden();
        $this->as($this->driver, 'DELETE', "company/members/{$this->colleagueMembership->id}")->assertForbidden();
        $this->as($this->driver, 'PUT', 'company', ['name' => 'Hacked'])->assertForbidden();
        $this->as($this->driver, 'GET', 'company/roles')->assertForbidden();
        $this->as($this->driver, 'GET', 'billing/invoices')->assertForbidden();
        // Legacy members.invite never allows an administrative role (ADR-752 anti-escalation).
        $this->as($this->driver, 'POST', 'company/members', ['email' => 'x@leezr.invalid', 'company_role_id' => $this->adminRole->id])->assertStatus(422);
        $this->assertSame('A', $this->a->fresh()->name);
    }

    // ── Fiche d'un collègue : facette accès sans la configuration des rôles ────

    public function test_people_file_hides_colleague_permissions_without_roles_view(): void
    {
        $ref = 'employee-'.$this->colleagueEmp->id;
        $account = $this->as($this->driver, 'GET', "company/people/{$ref}")->assertOk()->json('account');
        $this->assertNull($account['effective_permissions'], 'members.view (directory) never lists permission keys');

        $rolesViewer = $this->member($this->role('auditor', ['members.view', 'roles.view'], true));
        $this->assertIsArray($this->as($rolesViewer, 'GET', "company/people/{$ref}")->assertOk()->json('account.effective_permissions'));
    }

    public function test_people_file_never_returns_sensitive_values_without_sensitive_read(): void
    {
        \App\Core\Fields\FieldDefinitionCatalog::sync();
        $def = \App\Core\Fields\FieldDefinition::whereNull('company_id')->where('code', 'iban')->firstOrFail();
        \App\Core\Fields\FieldActivation::firstOrCreate(['company_id' => $this->a->id, 'field_definition_id' => $def->id], ['enabled' => true, 'required_override' => false, 'order' => 0]);
        \App\Core\Fields\FieldValue::create(['field_definition_id' => $def->id, 'model_type' => 'user', 'model_id' => $this->colleague->id, 'value' => 'FR7630006000011234567890189']);
        $body = $this->as($this->driver, 'GET', "company/members/{$this->colleagueMembership->id}")->assertOk()->getContent()
            .$this->as($this->driver, 'GET', "company/members/{$this->colleagueMembership->id}/fields")->assertOk()->getContent();
        $this->assertStringNotContainsString('FR7630006000011234567890189', $body);
    }

    // ── Pointages en cours / anomalies : périmètre temps ──────────────────────

    public function test_live_time_entries_and_anomalies_follow_the_time_scope(): void
    {
        $day = now()->toDateString();
        TimeEntry::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $this->colleagueEmp->id, 'date' => $day, 'clock_in' => now()->subHour(), 'status' => 'working', 'source' => 'manual']);
        TimeEntry::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $this->driverEmp->id, 'date' => $day, 'clock_in' => now()->subHour(), 'status' => 'working', 'source' => 'manual']);

        $employeeRole = $this->role('salarie', ['workforce.timesheet_view', 'workforce.timesheet_submit']);
        Membership::where('user_id', $this->driver->id)->update(['company_role_id' => $employeeRole->id]);

        $ids = array_column($this->as($this->driver, 'GET', 'workforce/time-entries/active')->assertOk()->json(), 'employee_id');
        $this->assertSame([$this->driverEmp->id], array_map('intval', $ids), 'own entry only');
        $anomalies = $this->as($this->driver, 'GET', "workforce/time-entries/anomalies?from={$day}&to={$day}")->assertOk()->json();
        $this->assertSame([], array_values(array_filter(array_map('intval', array_column($anomalies, 'employee_id')), fn ($id) => $id !== $this->driverEmp->id)));

        $hr = $this->member($this->role('hr', ['workforce.timesheet_view', 'workforce.timesheet_approve'], true));
        $all = array_map('intval', array_column($this->as($hr, 'GET', 'workforce/time-entries/active')->assertOk()->json(), 'employee_id'));
        sort($all);
        $expected = [$this->driverEmp->id, $this->colleagueEmp->id];
        sort($expected);
        $this->assertSame($expected, $all, '« voir tout » keeps the company view');
    }

    public function test_direct_manager_sees_live_entries_of_reports_only(): void
    {
        $managerUser = $this->member($this->role('mgr', ['workforce.timesheet_view']));
        $managerEmp = $this->employee(['user_id' => $managerUser->id, 'first_name' => 'Mona']);
        $this->driverEmp->update(['manager_id' => $managerEmp->id]);
        foreach ([$this->driverEmp, $this->colleagueEmp] as $e) {
            TimeEntry::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'employee_id' => $e->id, 'date' => now()->toDateString(), 'clock_in' => now()->subHour(), 'status' => 'working', 'source' => 'manual']);
        }
        $ids = array_map('intval', array_column($this->as($managerUser, 'GET', 'workforce/time-entries/active')->assertOk()->json(), 'employee_id'));
        $this->assertSame([$this->driverEmp->id], $ids);
    }

    // ── Feature flags : société active ────────────────────────────────────────

    public function test_feature_flags_resolve_for_the_active_company(): void
    {
        FeatureFlag::create(['key' => 'adr786_probe', 'enabled_globally' => false, 'company_overrides' => [(string) $this->a->id => true]]);
        $this->assertTrue($this->as($this->driver, 'GET', 'feature-flags')->assertOk()->json('adr786_probe'));

        $multi = $this->driver;
        Membership::create(['user_id' => $multi->id, 'company_id' => $this->b->id, 'role' => 'user', 'company_role_id' => $this->setUpCompanyRbac($this->b)->id]);
        $this->assertFalse($this->as($multi, 'GET', 'feature-flags', [], $this->b)->assertOk()->json('adr786_probe'), 'company B context');
    }

    // ── Multi-company ─────────────────────────────────────────────────────────

    public function test_non_member_company_context_is_refused(): void
    {
        $this->as($this->driver, 'GET', 'profile/payslips', [], $this->b)->assertForbidden();
        $this->as($this->driver, 'GET', 'workforce/me', [], $this->b)->assertForbidden();
    }

    public function test_another_company_objects_are_not_found(): void
    {
        $bOwner = User::factory()->create();
        Membership::create(['user_id' => $bOwner->id, 'company_id' => $this->b->id, 'role' => 'owner', 'company_role_id' => $this->setUpCompanyRbac($this->b)->id]);
        $payslip = $this->payslip($this->colleagueEmp, '2026-08');

        $this->assertSame(404, $this->as($bOwner, 'GET', "workforce/employees/{$this->colleagueEmp->id}", [], $this->b)->status());
        $this->assertSame(404, $this->as($bOwner, 'GET', 'company/people/employee-'.$this->colleagueEmp->id, [], $this->b)->status());
        $this->assertSame(404, $this->as($bOwner, 'GET', "profile/payslips/{$payslip->id}/download", [], $this->b)->status());
    }
}
