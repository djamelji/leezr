<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-864 — one Personnes interface : the former « Gestion RH » list (/company/people/hr) is folded into
 * GET /company/people. Its HR data (matricule, hire date, manager, contract) and filters are served on the HR facet
 * only, inside the actor's HR perimeter ; an accounts-only actor gets none of it.
 */
class Adr864OnePeopleInterfaceTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $hr;
    private User $accounts;
    private User $manager;
    private Employee $boss;
    private Employee $report;
    private Employee $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();
        $modules = ['workforce'];
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => $modules]);
        $this->company = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => 'workforce'], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        $this->setUpCompanyRbac($this->company);
        $this->member('owner', null, 'owner');
        $this->hr = $this->member('hr', $this->role('hr', ['workforce.view', 'workforce.view_all', 'workforce.manage'], true));
        $this->accounts = $this->member('accounts', $this->role('accounts', ['members.view', 'members.manage'], true));
        $this->manager = $this->member('manager', $this->role('manager', ['workforce.view']));

        $this->boss = $this->employee(['user_id' => $this->manager->id, 'first_name' => 'Paul', 'last_name' => 'Chef', 'employee_number' => 'M-001']);
        $this->report = $this->employee(['first_name' => 'Rita', 'last_name' => 'Report', 'employee_number' => 'R-777', 'hire_date' => '2025-03-10', 'manager_id' => $this->boss->id]);
        $this->outside = $this->employee(['first_name' => 'Otto', 'last_name' => 'Outside', 'employee_number' => 'X-999']);
    }

    private function role(string $key, array $permissions, bool $administrative = false): CompanyRole
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => $key, 'name' => ucfirst($key), 'is_administrative' => $administrative]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());

        return $role;
    }

    private function member(string $name, ?CompanyRole $role, string $baseRole = 'user'): User
    {
        $user = User::factory()->create(['first_name' => ucfirst($name), 'last_name' => 'Test']);
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => $baseRole, 'company_role_id' => $role?->id]);

        return $user;
    }

    private function employee(array $attrs): Employee
    {
        return Employee::create(array_merge(['company_id' => $this->company->id, 'email' => uniqid().'@co.test', 'status' => 'active', 'hire_date' => '2026-01-01'], $attrs));
    }

    private function people(User $u, string $query = ''): array
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/company/people?per_page=100'.$query)->assertOk()->json();
    }

    public function test_the_hr_facet_carries_the_former_hr_list_columns_and_filters(): void
    {
        $res = $this->people($this->hr);
        $rita = collect($res['data'])->firstWhere('employee_id', $this->report->id);
        $this->assertSame('R-777', $rita['employee_number']);
        $this->assertSame('2025-03-10', $rita['hire_date']);
        $this->assertSame('Paul Chef', $rita['manager']);
        $this->assertSame($this->boss->id, $rita['manager_id']);
        $this->assertArrayHasKey('Paul Chef', $res['filter_counts']['manager']);
        $this->assertIsArray($res['filter_counts']['contract_type']);

        // manager filter (by name, like team and position) and the matricule in the search
        $this->assertSame([$this->report->id], collect($this->people($this->hr, '&manager='.urlencode('Paul Chef'))['data'])->pluck('employee_id')->all());
        $this->assertSame([$this->outside->id], collect($this->people($this->hr, '&search=X-999')['data'])->pluck('employee_id')->all());
    }

    public function test_an_accounts_only_actor_gets_no_hr_data_and_no_hr_filter(): void
    {
        $res = $this->people($this->accounts);
        foreach ($res['data'] as $row) {
            $this->assertNull($row['employee_number'] ?? null);
            $this->assertNull($row['hire_date'] ?? null);
            $this->assertNull($row['manager_id'] ?? null);
        }
        $this->assertNull($res['filter_counts']['manager']);
        $this->assertNull($res['filter_counts']['contract_type']);
        $this->assertSame([], collect($this->people($this->accounts, '&search=R-777')['data'])->all(), 'the matricule is not searchable without the HR facet');
    }

    public function test_the_hr_perimeter_still_applies(): void
    {
        $refs = collect($this->people($this->manager)['data'])->pluck('employee_id')->filter()->all();
        $this->assertContains($this->report->id, $refs, 'a manager sees a direct report');
        $this->assertNotContains($this->outside->id, $refs, 'never an employee outside the perimeter');
        $this->assertSame([], collect($this->people($this->manager, '&search=X-999')['data'])->all());
    }
}
