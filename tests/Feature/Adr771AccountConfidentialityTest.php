<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-771 — an actor holding the HR facet (`workforce.view`) must not inherit the ACCOUNT facet
 * (`members.view`) through People or through the Workforce API.
 *
 *   HR facet      : HR identity (name on the HR record), professional contact details, employment.
 *   ACCOUNT facet : login identity (account e-mail, account name), membership status (active,
 *                   suspended, invited, pending…), role, administrative flags, authentication
 *                   information (password defined, last sign-in).
 *
 * The only account fact an HR profile keeps is the one written on the HR record itself : whether
 * an account is linked to it (`employee.user_id`).
 */
class Adr771AccountConfidentialityTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const LOGIN_EMAIL = 'secret.login@private-mail.test';

    private Company $company;
    private User $hr;
    private User $manager;
    private User $accounts;
    private User $admin;
    private User $person;
    private Employee $employee;
    private Employee $managerEmployee;
    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();

        $modules = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'];
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => $modules]);
        $this->company = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        foreach ($modules as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => $m], ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->member('owner', null, 'owner');
        $this->admin = $this->member('admin', $adminRole);
        $this->hr = $this->member('hr', $this->role('hr', ['workforce.view', 'workforce.manage'], false));
        $this->manager = $this->member('manager', $this->role('manager', ['workforce.view']));
        $this->accounts = $this->member('accounts', $this->role('accounts', ['members.view', 'members.manage', 'members.invite'], true));

        // The person : an administrator account, SUSPENDED, whose account identity differs from her HR identity.
        $this->person = User::factory()->create(['first_name' => 'Loginfirst', 'last_name' => 'Loginlast', 'email' => self::LOGIN_EMAIL, 'last_login_at' => now()]);
        $this->membership = $this->company->memberships()->create(['user_id' => $this->person->id, 'role' => 'user', 'company_role_id' => $adminRole->id,
            'suspended_at' => now(), 'suspension_reason' => 'Confidential reason']);

        $this->managerEmployee = $this->employee(['user_id' => $this->manager->id, 'last_name' => 'Manager']);
        $this->employee = $this->employee(['user_id' => $this->person->id, 'first_name' => 'Hrfirst', 'last_name' => 'Hrlast',
            'email' => 'pro.contact@company.test', 'manager_id' => $this->managerEmployee->id]);
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

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge(['company_id' => $this->company->id, 'employee_number' => 'E'.uniqid(), 'first_name' => 'Jean', 'last_name' => 'Dupont',
            'email' => uniqid().'@co.test', 'status' => 'active', 'hire_date' => '2026-01-01'], $attrs));
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    /** Every read an HR-facet actor can make about the person, through People AND through Workforce. */
    private function hrReads(): array
    {
        $e = $this->employee->id;

        return [
            "/api/company/people/employee-{$e}",
            '/api/company/people?per_page=100',
            "/api/company/people?search=Hrlast",
            "/api/workforce/employees/{$e}",
            "/api/workforce/employees/{$e}/file",
            '/api/workforce/employees?perPage=100',
        ];
    }

    public function test_hr_facet_never_carries_login_identity_membership_status_or_authentication_data(): void
    {
        foreach ([$this->hr, $this->manager] as $actor) {
            foreach ($this->hrReads() as $uri) {
                $body = $this->as($actor)->getJson($uri)->assertOk()->getContent();

                foreach ([self::LOGIN_EMAIL, 'Loginfirst', 'Loginlast', 'Confidential reason'] as $secret) {
                    $this->assertStringNotContainsString($secret, $body, "{$uri} leaks « {$secret} » to an HR-facet actor");
                }
                foreach (['"suspended"', '"connected"', 'effective_permissions":[', 'last_login_at', 'password_set_at'] as $marker) {
                    $this->assertStringNotContainsString($marker, $body, "{$uri} leaks membership/authentication status ({$marker})");
                }
            }
        }

        $file = $this->as($this->hr)->getJson("/api/company/people/employee-{$this->employee->id}")->assertOk();
        $file->assertJsonPath('identity.first_name', 'Hrfirst')->assertJsonPath('identity.last_name', 'Hrlast')
            ->assertJsonPath('identity.display_name', 'Hrfirst Hrlast')->assertJsonPath('identity.login_email', null)
            ->assertJsonPath('identity.contact_email', 'pro.contact@company.test')
            ->assertJsonPath('account.state', null)->assertJsonPath('account.has_access', null)
            ->assertJsonPath('account.is_admin', null)->assertJsonPath('account.is_owner', null)
            ->assertJsonPath('account.role', null)->assertJsonPath('account.invitation', null)
            ->assertJsonPath('membership_id', null)
            // The one fact that lives on the HR record : an account is linked.
            ->assertJsonPath('account.has_account', true)
            ->assertJsonPath('employment.onboarding.account_state', null);
    }

    public function test_access_status_endpoint_belongs_to_the_account_facet(): void
    {
        $uri = "/api/workforce/employees/{$this->employee->id}/access";

        // HR facet alone : refused (it returned the account e-mail, state, role, suspension reason).
        $this->as($this->hr)->getJson($uri)->assertStatus(403);
        $this->as($this->manager)->getJson($uri)->assertStatus(403);

        // Account facet WITHOUT Workforce permission : allowed (it was a stray 403 for the People access dialog).
        $this->as($this->accounts)->getJson($uri)->assertOk()->assertJsonPath('state', 'suspended');
        $this->as($this->admin)->getJson($uri)->assertOk();
    }

    public function test_account_facet_and_full_administrators_keep_their_view(): void
    {
        $admin = $this->as($this->admin)->getJson("/api/company/people/employee-{$this->employee->id}")->assertOk();
        $admin->assertJsonPath('identity.login_email', self::LOGIN_EMAIL)->assertJsonPath('account.state', 'suspended')
            ->assertJsonPath('account.is_admin', true)->assertJsonPath('account.has_account', true)
            ->assertJsonPath('membership_id', $this->membership->id)->assertJsonPath('identity.contact_email', 'pro.contact@company.test');

        // Account facet without HR : login identity and status, no HR contact details.
        $accounts = $this->as($this->accounts)->getJson("/api/company/people/user-{$this->person->id}")->assertOk();
        $accounts->assertJsonPath('identity.login_email', self::LOGIN_EMAIL)->assertJsonPath('account.state', 'suspended')
            ->assertJsonPath('identity.contact_email', null)->assertJsonPath('employment', null);
        $this->assertStringNotContainsString('pro.contact@company.test', $accounts->getContent());

        // The person herself keeps her own account data in her Workforce self-service.
        $this->membership->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        $this->as($this->person)->getJson('/api/workforce/me')->assertOk()->assertJsonPath('employee.user.email', self::LOGIN_EMAIL);
    }

    public function test_an_hr_record_without_account_says_so_without_exposing_anything_else(): void
    {
        $solo = $this->employee(['last_name' => 'Solo']);
        $this->as($this->hr)->getJson("/api/company/people/employee-{$solo->id}")->assertOk()
            ->assertJsonPath('account.has_account', false)->assertJsonPath('account.state', null)
            ->assertJsonPath('employment.onboarding.account', false);
        $this->as($this->hr)->getJson("/api/company/people/employee-{$this->employee->id}")->assertJsonPath('employment.onboarding.account', true);
    }
}
