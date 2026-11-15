<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmployeeNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-788 — field access : documentary supervision (documents.view) requires a management role
 * (structure surface, ADR-373) ; personal documents stay reachable ; historical employee numbers
 * assigned by an idempotent command whose simulation writes nothing ; targeted role revocation report.
 */
class Adr788FieldAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const DRIVER = ['documents.view', 'members.invite', 'members.view', 'settings.view', 'shipments.manage_status', 'shipments.view_own', 'theme.manage', 'theme.view'];

    private Company $a;
    private Company $b;
    private User $driver;
    private User $hr;
    private User $owner;
    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();
        Storage::fake('local');
        $this->a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        foreach ([$this->a, $this->b] as $c) {
            $this->activateCompanyModules($c);
        }
        $admin = $this->setUpCompanyRbac($this->a);
        $this->owner = $this->member($this->a, $admin, 'owner');
        $this->driver = $this->member($this->a, $this->role($this->a, 'driver', self::DRIVER, false));
        $this->hr = $this->member($this->a, $this->role($this->a, 'docs', ['documents.view', 'members.view'], true));
        $this->colleague = $this->member($this->a, $admin);
    }

    private function role(Company $c, string $key, array $perms, bool $administrative): CompanyRole
    {
        $r = CompanyRole::create(['company_id' => $c->id, 'key' => $key, 'name' => $key, 'is_system' => true, 'is_administrative' => $administrative]);
        $r->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());

        return $r;
    }

    private function member(Company $c, ?CompanyRole $role, string $base = 'user'): User
    {
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => $base, 'company_role_id' => $role?->id]);

        return $u;
    }

    private function as(User $u, string $uri, ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->a)->id])->getJson('/api/'.$uri);
    }

    // ── §3 Confidentialité documentaire ───────────────────────────────────────

    public function test_new_driver_roles_get_no_documentary_supervision_nor_invitation(): void
    {
        $c = Company::create(['name' => 'New', 'slug' => 'new-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        \App\Core\Jobdomains\JobdomainGate::assignToCompany($c, 'logistique');
        $driver = CompanyRole::where('company_id', $c->id)->where('key', 'driver')->firstOrFail();
        $keys = $driver->permissions()->pluck('key')->all();
        $this->assertNotContains('documents.view', $keys);
        $this->assertNotContains('members.invite', $keys);
        $this->assertContains('members.view', $keys, 'annuaire conservé (ADR-777/787)');
    }

    public function test_field_worker_without_documents_view_keeps_his_own_space_and_no_supervision(): void
    {
        $field = $this->member($this->a, $this->role($this->a, 'field', ['members.view', 'shipments.view_own'], false));
        $this->actingAs($field)->withHeaders(['X-Company-Id' => $this->a->id])->getJson('/api/profile/documents')->assertOk();
        $this->actingAs($field)->withHeaders(['X-Company-Id' => $this->a->id])->getJson('/api/profile/payslips')->assertOk();
        foreach (['company/documents', 'company/documents/compliance', 'company/documents/activity', 'company/documents/search'] as $uri) {
            $this->actingAs($field)->withHeaders(['X-Company-Id' => $this->a->id])->getJson('/api/'.$uri)->assertForbidden();
        }
    }

    public function test_legacy_driver_role_still_never_reaches_confidential_documents_or_payslips(): void
    {
        $m = Membership::where('user_id', $this->colleague->id)->firstOrFail()->id;
        // (cas avec pièce réelle : DmsM1FoundationsTest « manager view-only » → 403 sur la pièce d'identité)
        $this->assertContains($this->as($this->driver, "company/members/{$m}/documents/id_card/download")->status(), [403, 404]);
        $this->assertContains($this->as($this->driver, 'company/people/user-'.$this->colleague->id.'/payslips')->status(), [403, 404]);
    }

    public function test_documents_view_revocation_is_prepared_as_a_simulation(): void
    {
        $this->artisan('roles:revoke-permission', ['role_key' => 'driver', 'permission' => 'documents.view'])
            ->expectsOutputToContain('"apply":false')->assertSuccessful();
        $this->assertTrue(CompanyRole::where('company_id', $this->a->id)->where('key', 'driver')->firstOrFail()->permissions()->where('key', 'documents.view')->exists());
    }

    // ── §2 Matricules historiques ─────────────────────────────────────────────

    private function employee(Company $c, ?string $number): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => $number]);
    }

    public function test_dry_run_writes_nothing_and_predicts_the_applied_numbers(): void
    {
        $this->employee($this->a, 'EMP-000003');
        $x = $this->employee($this->a, null);
        $y = $this->employee($this->a, null);
        $z = $this->employee($this->b, null);

        $this->artisan('employees:assign-missing-numbers', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('company_sequences')->count(), 'no counter created');
        $this->assertNull($x->fresh()->employee_number);
        $this->assertSame(['EMP-000004', 'EMP-000005'], EmployeeNumbering::preview($this->a->id, 2));

        $this->artisan('employees:assign-missing-numbers')->assertSuccessful();
        $this->assertSame(['EMP-000004', 'EMP-000005', 'EMP-000001'], [$x->fresh()->employee_number, $y->fresh()->employee_number, $z->fresh()->employee_number]);
        $this->assertSame('EMP-000003', Employee::withoutGlobalScopes()->where('employee_number', 'EMP-000003')->value('employee_number'), 'never overwritten');
        $this->assertSame(3, \App\Core\Audit\CompanyAuditLog::where('action', 'employee.number_assigned')->count());

        $this->artisan('employees:assign-missing-numbers')->assertSuccessful();
        $this->assertSame(3, \App\Core\Audit\CompanyAuditLog::where('action', 'employee.number_assigned')->count(), 'idempotent');
        $this->assertSame('EMP-000006', EmployeeNumbering::next($this->a->id), 'sequence continues, nothing reused');
    }

    public function test_existing_sequence_is_preserved(): void
    {
        $this->assertSame('EMP-000001', EmployeeNumbering::next($this->a->id));
        $this->assertSame('EMP-000002', EmployeeNumbering::next($this->a->id)); // drawn, never assigned
        $x = $this->employee($this->a, null);
        $this->assertSame(['EMP-000003'], EmployeeNumbering::preview($this->a->id, 1));
        $this->artisan('employees:assign-missing-numbers', ['--company' => [$this->a->id]])->assertSuccessful();
        $this->assertSame('EMP-000003', $x->fresh()->employee_number);
    }

    // ── §1 Driver ─────────────────────────────────────────────────────────────

    public function test_revocation_simulation_lists_roles_and_memberships_without_writing(): void
    {
        $this->artisan('roles:revoke-permission', ['role_key' => 'driver', 'permission' => 'members.invite'])
            ->expectsOutputToContain('"membership_id"')->assertSuccessful();
        $this->assertTrue(CompanyRole::where('company_id', $this->a->id)->where('key', 'driver')->firstOrFail()->permissions()->where('key', 'members.invite')->exists());
    }
}
