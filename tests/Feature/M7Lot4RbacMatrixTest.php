<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-770 (M7 LOT 4) — multi-facet RBAC matrix, by DIRECT HTTP calls (what the frontend hides is
 * irrelevant here). Ten profiles × the People / Members / Workforce / Documents / self-service APIs.
 *
 * Every cell is an explicit expectation :
 *   200/201 = allowed ; 403 = legitimate refusal (the profile lacks the facet) ; 404 = out of the
 *   actor's data scope or not found ; 422 = allowed to try, refused by a business authority.
 * A cell that differs from the table is either a leak (2xx where a refusal is expected) or a stray
 * refusal (403 where the profile is entitled) — both fail the test.
 */
class M7Lot4RbacMatrixTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private const MODULES = ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave', 'workforce_signature'];

    private Company $a;
    private Company $b;
    private CompanyRole $plainRole;

    /** @var array<string, User> */
    private array $p = [];
    private Employee $managerEmp;
    private Employee $report;
    private Employee $outsider;
    private User $target;          // plain member of A, target of account operations
    private Membership $targetMembership;
    private PublishedPayslip $outsiderPayslip;

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
        $adminA = $this->setUpCompanyRbac($this->a);
        $adminB = $this->setUpCompanyRbac($this->b);
        $this->plainRole = $this->role('plain', []);

        $this->member('owner', null, 'owner');
        $this->p = [
            'A_admin' => $this->member('admin', $adminA),
            'B_members' => $this->member('membersmgr', $this->role('membersmgr', ['members.view', 'members.manage', 'members.invite', 'members.credentials'], true)),
            'C_hr' => $this->member('hr', $this->role('hr', ['workforce.view', 'workforce.manage', 'workforce.contracts', 'workforce.compensation_read', 'workforce.compensation_manage'], true)),
            'D_manager' => $this->member('manager', $this->role('manager', ['workforce.view'])),
            'E_docs' => $this->member('docs', $this->role('docs', ['documents.view', 'documents.manage'], true)),
            'F_payslips' => $this->member('payslips', $this->role('payslips', ['payslips.import', 'payslips.review', 'payslips.publish', 'payslips.download'], true)),
            'G_self' => $this->member('selfuser', $this->plainRole),
            'H_noemp' => $this->member('noemp', $this->plainRole),
            'I_adminemp' => $this->member('adminemp', $adminA),
            'J_multi' => $this->member('multi', $this->plainRole),
        ];
        $this->b->memberships()->create(['user_id' => $this->p['J_multi']->id, 'role' => 'user', 'company_role_id' => $adminB->id]);

        $this->managerEmp = $this->employee($this->a, ['user_id' => $this->p['D_manager']->id, 'last_name' => 'Manager']);
        $this->report = $this->employee($this->a, ['manager_id' => $this->managerEmp->id, 'last_name' => 'Report']);
        $this->outsider = $this->employee($this->a, ['last_name' => 'Outsider']);
        $this->employee($this->a, ['user_id' => $this->p['G_self']->id, 'last_name' => 'Self']);
        $this->employee($this->a, ['user_id' => $this->p['I_adminemp']->id, 'last_name' => 'AdminEmp']);

        $this->target = $this->member('target', $this->plainRole);
        $this->targetMembership = Membership::withoutGlobalScopes()->where('company_id', $this->a->id)->where('user_id', $this->target->id)->firstOrFail();

        Storage::disk('local')->put('payslips/o.pdf', '%PDF');
        $this->outsiderPayslip = PublishedPayslip::create(['company_id' => $this->a->id, 'document_subject_type' => 'employee', 'document_subject_id' => $this->outsider->id,
            'period' => '2026-01', 'file_path' => 'payslips/o.pdf', 'file_name' => 'b.pdf', 'file_sha256' => str_repeat('a', 64), 'version_no' => 1, 'created_at' => now()]);
    }

    private function role(string $key, array $permissions, bool $administrative = false): CompanyRole
    {
        $role = CompanyRole::create(['company_id' => $this->a->id, 'key' => $key, 'name' => ucfirst($key), 'is_administrative' => $administrative]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());

        return $role;
    }

    private function member(string $name, ?CompanyRole $role, string $baseRole = 'user'): User
    {
        $user = User::factory()->create(['first_name' => ucfirst($name), 'last_name' => 'Test']);
        $this->a->memberships()->create(['user_id' => $user->id, 'role' => $baseRole, 'company_role_id' => $role?->id]);

        return $user;
    }

    private function employee(Company $company, array $attrs = []): Employee
    {
        return Employee::create(array_merge(['company_id' => $company->id, 'employee_number' => 'E'.uniqid(), 'first_name' => 'Jean', 'last_name' => 'Dupont',
            'email' => uniqid().'@co.test', 'status' => 'active', 'hire_date' => '2026-01-01'], $attrs));
    }

    private function hit(string $profile, string $method, string $uri, array $body = [], ?Company $company = null)
    {
        return $this->actingAs($this->p[$profile])->withHeaders(['X-Company-Id' => ($company ?? $this->a)->id])->json($method, '/api'.$uri, $body);
    }

    /**
     * The matrix. Columns, in order : A admin · B members manager · C HR · D team manager ·
     * E documents manager · F payslips manager · G self-service employee · H account without HR
     * record · I admin who is also an employee · J multi-company member (plain in A).
     *
     * @return array<string, array{0: string, 1: string, 2: array, 3: list<int>}>
     */
    private function matrix(): array
    {
        $o = $this->outsider->id;
        $r = $this->report->id;
        $m = $this->targetMembership->id;
        $u = $this->target->id;
        $contract = ['contract_type' => 'cdi', 'work_model_key' => 'horaire', 'start_date' => '2027-01-01', 'weekly_hours' => 35];

        return [
            // ── People (unified list & file) ─────────────────────── A    B    C    D    E    F    G    H    I    J
            'people list'                     => ['GET', '/company/people', [],                                             [200, 200, 200, 200, 403, 403, 403, 403, 200, 403]],
            'person file (employee, outsider)' => ['GET', "/company/people/employee-{$o}", [],                               [200, 404, 200, 404, 403, 403, 403, 403, 200, 403]],
            'person file (employee, report)'  => ['GET', "/company/people/employee-{$r}", [],                               [200, 404, 200, 200, 403, 403, 403, 403, 200, 403]],
            'person file (account)'           => ['GET', "/company/people/user-{$u}", [],                                   [200, 200, 404, 404, 403, 403, 403, 403, 200, 403]],
            'person history'                  => ['GET', "/company/people/user-{$u}/history", [],                           [200, 403, 403, 403, 403, 403, 403, 403, 200, 403]],
            'resolve membership id'           => ['GET', "/company/people/resolve?membership_id={$m}", [],                  [200, 200, 403, 403, 403, 403, 403, 403, 200, 403]],
            'resolve employee id'             => ['GET', "/company/people/resolve?employee_id={$o}", [],                    [200, 403, 200, 200, 403, 403, 403, 403, 200, 403]],

            // ── Members (accounts) ──────────────────────────────────
            'members directory'               => ['GET', '/company/members', [],                                            [200, 200, 403, 403, 200, 403, 403, 403, 200, 403]],
            'member profile'                  => ['GET', "/company/members/{$m}", [],                                       [200, 200, 403, 403, 403, 403, 403, 403, 200, 403]],
            'rename member'                   => ['PUT', "/company/members/{$m}", ['last_name' => 'Renamed'],               [200, 200, 403, 403, 403, 403, 403, 403, 200, 403]],
            'suspend member'                  => ['POST', "/company/members/{$m}/suspend", [],                              [200, 200, 403, 403, 403, 403, 403, 403, 200, 403]],
            'restore member'                  => ['POST', "/company/members/{$m}/restore", [],                              [200, 200, 403, 403, 403, 403, 403, 403, 200, 403]],
            'reset member password (link)'    => ['POST', "/company/members/{$m}/reset-password", [],                       [200, 200, 403, 403, 403, 403, 403, 403, 200, 403]],
            'roles catalogue'                 => ['GET', '/company/roles', [],                                              [200, 403, 403, 403, 403, 403, 403, 403, 200, 403]],

            // ── Workforce (HR) ──────────────────────────────────────
            'employees list'                  => ['GET', '/workforce/employees', [],                                        [200, 403, 200, 200, 403, 403, 403, 403, 200, 403]],
            'employee read (outsider)'        => ['GET', "/workforce/employees/{$o}", [],                                   [200, 403, 200, 403, 403, 403, 403, 403, 200, 403]],
            'employee read (report)'          => ['GET', "/workforce/employees/{$r}", [],                                   [200, 403, 200, 200, 403, 403, 403, 403, 200, 403]],
            'employee update'                 => ['PUT', "/workforce/employees/{$o}", ['phone' => '0102030405'],            [200, 403, 200, 403, 403, 403, 403, 403, 200, 403]],
            'contracts read (outsider)'       => ['GET', "/workforce/employees/{$o}/contracts", [],                         [200, 403, 200, 403, 403, 403, 403, 403, 200, 403]],
            'contract create with salary'     => ['POST', "/workforce/employees/{$r}/contracts", $contract + ['base_salary_cents' => 200000], [201, 403, 422, 403, 403, 403, 403, 403, 422, 403]],
            'hr record for a member'          => ['POST', '/workforce/employees/from-member', ['user_id' => $u, 'hire_date' => '2026-05-01'], [201, 403, 403, 403, 403, 403, 403, 403, 422, 403]],
            'signature management list'       => ['GET', '/workforce/signature', [],                                        [200, 403, 403, 403, 403, 403, 403, 403, 200, 403]],

            // ── Documents & payslips of OTHERS ──────────────────────
            'person documents (outsider)'     => ['GET', "/company/people/employee-{$o}/documents", [],                     [200, 403, 403, 403, 200, 403, 403, 403, 200, 403]],
            'person payslips (outsider)'      => ['GET', "/company/people/employee-{$o}/payslips", [],                      [200, 403, 200, 403, 200, 200, 403, 403, 200, 403]],
            'payslip download (outsider)'     => ['GET', "/company/people/employee-{$o}/payslips/{$this->outsiderPayslip->id}/download", [], [200, 403, 200, 403, 200, 200, 403, 403, 200, 403]],
            'payslip imports'                 => ['GET', '/company/payslip-imports', [],                                    [200, 403, 403, 403, 403, 200, 403, 403, 200, 403]],
            'document compliance'             => ['GET', '/company/documents/compliance', [],                               [200, 403, 403, 403, 200, 403, 403, 403, 200, 403]],
            'document requests queue'         => ['GET', '/company/document-requests/queue', [],                            [200, 403, 403, 403, 200, 403, 403, 403, 200, 403]],
            'document campaigns'              => ['GET', '/company/document-campaigns', [],                                 [200, 403, 403, 403, 200, 403, 403, 403, 200, 403]],

            // ── Self-service (ownership, no permission) ─────────────
            'my space'                        => ['GET', '/profile/space', [],                                              [200, 200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'my profile'                      => ['GET', '/profile', [],                                                    [200, 200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'my documents'                    => ['GET', '/profile/documents', [],                                          [200, 200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'my payslips'                     => ['GET', '/profile/payslips', [],                                           [200, 200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'my signatures'                   => ['GET', '/workforce/signature/me', [],                                     [200, 200, 200, 200, 200, 200, 200, 200, 200, 200]],
            'my workforce profile'            => ['GET', '/workforce/me', [],                                               [404, 404, 404, 200, 404, 404, 200, 404, 200, 404]],
        ];
    }

    public function test_every_profile_gets_exactly_its_facets_by_direct_http(): void
    {
        $profiles = array_keys($this->p);
        $diff = [];

        foreach ($this->matrix() as $label => [$method, $uri, $body, $expected]) {
            foreach ($profiles as $i => $profile) {
                $status = $this->hit($profile, $method, $uri, $body)->getStatusCode();
                if ($status !== $expected[$i]) {
                    $diff[] = sprintf('%-34s %-11s expected %d, got %d', $label, $profile, $expected[$i], $status);
                }
            }
        }

        $this->assertSame([], $diff, "RBAC matrix mismatches:\n".implode("\n", $diff));
    }

    public function test_payloads_carry_only_the_facets_of_the_profile(): void
    {
        $o = $this->outsider->id;
        // A contract with a salary on the outsider, to look for leaks.
        $this->hit('A_admin', 'POST', "/workforce/employees/{$o}/contracts", ['contract_type' => 'cdi', 'work_model_key' => 'horaire', 'start_date' => '2026-02-01', 'weekly_hours' => 35, 'base_salary_cents' => 314159])->assertStatus(201);
        $contractId = \App\Core\Workforce\EmploymentContract::withoutGlobalScopes()->where('employee_id', $o)->value('id');
        $this->hit('A_admin', 'POST', "/workforce/employees/{$o}/contracts/{$contractId}/activate")->assertOk();

        // B — accounts manager : the account facet of a person, never an HR byte.
        $list = $this->hit('B_members', 'GET', '/company/people')->assertOk();
        $list->assertJsonPath('facets.access', true)->assertJsonPath('facets.hr', false);
        $this->assertNull(collect($list->json('data'))->firstWhere('employee_id', $o), 'HR-only people are not listed for the access facet');
        $file = $this->hit('B_members', 'GET', "/company/people/user-{$this->p['D_manager']->id}")->assertOk();
        $file->assertJsonPath('employment', null)->assertJsonPath('can.view_hr', false)->assertJsonPath('can.view_compensation', false);
        $this->assertStringNotContainsString('314159', $this->hit('B_members', 'GET', '/company/people')->getContent());

        // C — HR without Members : employment and salary, never the account role / permissions.
        $file = $this->hit('C_hr', 'GET', "/company/people/employee-{$o}")->assertOk();
        $file->assertJsonPath('can.view_access', false)->assertJsonPath('account.role', null)->assertJsonPath('account.effective_permissions', null)
            ->assertJsonPath('employment.contract.compensation.base_salary_cents', 314159);
        $this->hit('C_hr', 'GET', '/company/people')->assertJsonPath('facets.access', false);

        // D — team manager : himself + direct reports only, no salary.
        $ids = collect($this->hit('D_manager', 'GET', '/company/people')->assertOk()->json('data'))->pluck('employee_id')->filter()->sort()->values()->all();
        $this->assertSame([$this->managerEmp->id, $this->report->id], $ids);
        $this->assertStringNotContainsString('314159', $this->hit('D_manager', 'GET', "/company/people/employee-{$this->report->id}")->getContent());
        $ids = collect($this->hit('D_manager', 'GET', '/workforce/employees?perPage=100')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->managerEmp->id, $this->report->id], $ids);

        // E — documents manager : a person picker, not a members directory.
        $row = collect($this->hit('E_docs', 'GET', '/company/members')->assertOk()->json('members'))->first();
        $this->assertSame(['id', 'first_name', 'last_name', 'display_name'], array_keys($row['user']));

        // F — payslips manager without documents.view : payslips yes, the other documents no.
        $this->hit('F_payslips', 'GET', "/company/people/employee-{$o}/payslips")->assertOk()->assertJsonCount(1, 'payslips');
        $this->hit('F_payslips', 'GET', "/company/people/employee-{$o}/documents")->assertStatus(403);

        // G / H — self-service : own space only ; H has no fake HR record.
        $this->hit('G_self', 'GET', '/profile/space')->assertJsonPath('capabilities.employment', true);
        $this->hit('H_noemp', 'GET', '/profile/space')->assertJsonPath('has_employee', false)->assertJsonPath('capabilities.employment', false);
        $this->assertSame([], $this->hit('G_self', 'GET', '/profile/payslips')->json('payslips'));

        // I — administrator AND employee : both, without mixing them.
        $this->hit('I_adminemp', 'GET', '/profile/space')->assertJsonPath('capabilities.employment', true);
        $this->assertSame([], $this->hit('I_adminemp', 'GET', '/profile/payslips')->json('payslips'), 'administering payslips does not make them MINE');
        $this->hit('I_adminemp', 'GET', "/company/people/employee-{$o}/payslips")->assertOk()->assertJsonCount(1, 'payslips');
    }

    public function test_company_isolation_holds_for_every_profile(): void
    {
        $foreign = $this->employee($this->b, ['last_name' => 'Foreign']);
        $foreignMember = Membership::withoutGlobalScopes()->where('company_id', $this->b->id)->where('user_id', $this->p['J_multi']->id)->firstOrFail();

        foreach (array_keys($this->p) as $profile) {
            if ($profile === 'J_multi') {
                continue;
            }
            // Not a member of B : nothing answers, whatever the permission held in A.
            foreach (['/company/people', '/profile/space', '/profile/payslips', "/company/people/employee-{$foreign->id}", '/workforce/me'] as $uri) {
                $this->assertSame(403, $this->hit($profile, 'GET', $uri, [], $this->b)->getStatusCode(), "{$profile} must not reach B through {$uri}");
            }
            // And ids of B never resolve from A.
            $this->assertContains($this->hit($profile, 'GET', "/company/people/employee-{$foreign->id}")->getStatusCode(), [403, 404], "{$profile} / foreign employee");
            $this->assertContains($this->hit($profile, 'POST', "/company/members/{$foreignMember->id}/suspend")->getStatusCode(), [403, 404], "{$profile} / foreign membership");
            $this->assertContains($this->hit($profile, 'PUT', "/workforce/employees/{$foreign->id}", ['phone' => '1'])->getStatusCode(), [403, 404], "{$profile} / foreign employee update");
        }
        $this->assertNull($foreignMember->fresh()->suspended_at);
        $this->assertNull($foreign->fresh()->phone);

        // J — plain member in A, administrator in B : each company answers with ITS role.
        $this->hit('J_multi', 'GET', '/company/people')->assertStatus(403);
        $inB = $this->hit('J_multi', 'GET', '/company/people', [], $this->b)->assertOk()->json('data');
        $this->assertNotEmpty($inB);
        $this->assertEmpty(array_intersect(array_filter(array_column($inB, 'employee_id')), Employee::withoutGlobalScopes()->where('company_id', $this->a->id)->pluck('id')->all()), 'no person of A in the list of B');
        $this->hit('J_multi', 'GET', "/company/people/employee-{$this->outsider->id}", [], $this->b)->assertStatus(404);
        $this->hit('J_multi', 'GET', "/company/people/employee-{$this->outsider->id}/payslips/{$this->outsiderPayslip->id}/download", [], $this->b)->assertStatus(404);
        $this->hit('J_multi', 'PUT', "/company/members/{$this->targetMembership->id}", ['last_name' => 'X'], $this->b)->assertStatus(404);
    }
}
