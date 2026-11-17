<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-754 (P2) — liste Collaborateurs unifiée + permissions PAR FACETTE (accès vs RH).
 */
class PeoplePhase2Test extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $hrOnly;     // workforce.view, PAS members.view
    private User $accessOnly; // members.view, PAS workforce.view
    private CompanyRole $hrOnlyRole;
    private CompanyRole $accessOnlyRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')
            ->update(['default_modules' => ['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave']]);

        $this->company = Company::create(['name' => 'P2 Co', 'slug' => 'p2-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        foreach (['workforce', 'workforce_documents', 'workforce_planning', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $this->company->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($this->company);

        $this->hrOnlyRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'hronly', 'name' => 'HR', 'is_administrative' => false]);
        // ADR-767 : un profil RH (vue Company) = workforce.view + workforce.manage ; `workforce.view`
        // seul est borné à soi + rapports directs (couvert par Adr767SecurityHardeningTest).
        $this->hrOnlyRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.manage'])->pluck('id')->all());

        $this->accessOnlyRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'accessonly', 'name' => 'Access', 'is_administrative' => false]);
        // ADR-816 : la facette ACCÈS (compte, rôle) = annuaire + administration des accès (members.invite).
        $this->accessOnlyRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['members.view', 'members.invite'])->pluck('id')->all());

        $this->hrOnly = User::factory()->create();
        $this->accessOnly = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->hrOnly->id, 'role' => 'user', 'company_role_id' => $this->hrOnlyRole->id]);
        $this->company->memberships()->create(['user_id' => $this->accessOnly->id, 'role' => 'user', 'company_role_id' => $this->accessOnlyRole->id]);
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'company_id' => $this->company->id, 'employee_number' => 'E'.uniqid(),
            'first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => uniqid().'@co.test',
            'status' => 'active', 'hire_date' => '2026-01-01', 'phone' => '0600000000',
        ], $attrs));
    }

    // ── Facettes sur la FICHE ──

    public function test_hr_facet_sees_employment_not_access(): void
    {
        $emp = $this->employee(['user_id' => $this->accessOnly->id]); // personne reliée
        $data = $this->as($this->hrOnly)->getJson("/api/company/people/employee-{$emp->id}")->assertOk()->json();
        $this->assertNotNull($data['employment']);                    // données RH visibles
        $this->assertContains('employment', $data['available_sections']);
        $this->assertNull($data['account']['role']);                   // rôle MASQUÉ (pas de members.view)
        $this->assertNotContains('access', $data['available_sections']);
        $this->assertNotNull($data['identity']['phone']);             // champ RH visible
    }

    public function test_access_facet_sees_account_not_hr(): void
    {
        $emp = $this->employee(['user_id' => $this->accessOnly->id]);
        $data = $this->as($this->accessOnly)->getJson("/api/company/people/employee-{$emp->id}")->assertOk()->json();
        $this->assertNull($data['employment']);                        // données RH MASQUÉES
        $this->assertNotContains('employment', $data['available_sections']);
        $this->assertNotNull($data['account']['role']);                // rôle visible
        $this->assertContains('access', $data['available_sections']);
        $this->assertNull($data['identity']['phone']);                 // champ RH masqué
    }

    // ── Liste unifiée ──

    public function test_list_dedups_linked_person_single_row(): void
    {
        $emp = $this->employee(['user_id' => $this->accessOnly->id]); // accessOnly = compte + fiche RH
        // admin (toutes facettes)
        $admin = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $admin->id, 'role' => 'owner']);
        $res = $this->as($admin)->getJson('/api/company/people?per_page=100')->assertOk()->json();
        $refs = collect($res['data'])->pluck('person_ref');
        // accessOnly apparaît UNE fois (canonique = employee), pas deux.
        $this->assertSame(1, $refs->filter(fn ($r) => $r === "employee-{$emp->id}")->count());
        $this->assertFalse($refs->contains("user-{$this->accessOnly->id}"), 'linked person must not appear as a separate user row');
    }

    public function test_list_hr_only_shows_employment_no_role(): void
    {
        $this->employee();
        $res = $this->as($this->hrOnly)->getJson('/api/company/people?per_page=100')->assertOk()->json();
        $this->assertTrue($res['facets']['hr']);
        $this->assertFalse($res['facets']['access']);
        $row = collect($res['data'])->firstWhere('employment_status', 'active');
        $this->assertNotNull($row);
        $this->assertNull($row['role']); // pas de rôle sans facette accès
    }

    public function test_list_access_only_shows_accounts_no_employment(): void
    {
        $this->employee(); // fiche RH SANS compte → invisible pour un acteur accès-seul
        $res = $this->as($this->accessOnly)->getJson('/api/company/people?per_page=100')->assertOk()->json();
        $this->assertTrue($res['facets']['access']);
        $this->assertFalse($res['facets']['hr']);
        // Les lignes sont des comptes ; aucune donnée d'emploi.
        foreach ($res['data'] as $r) {
            $this->assertNull($r['employment_status']);
        }
        // La fiche RH pure (sans compte) n'est pas listée pour l'accès-seul.
        $this->assertFalse(collect($res['data'])->contains(fn ($r) => str_starts_with((string) $r['person_ref'], 'employee-')));
    }

    public function test_list_search_and_pagination(): void
    {
        $this->employee(['first_name' => 'Zoe', 'last_name' => 'Unique']);
        $res = $this->as($this->hrOnly)->getJson('/api/company/people?search=Zoe')->assertOk()->json();
        $this->assertSame(1, $res['total']);
        $this->assertSame('Zoe Unique', $res['data'][0]['name']);
    }

    public function test_roles_view_facet_sees_roles_not_people_list(): void
    {
        // ADR-755 : roles.view donne accès aux DÉFINITIONS de rôles, PAS à la liste des personnes.
        // roles.view est une permission admin → rôle administratif (mais SANS members.view/workforce.view).
        $rolesRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'rolesview', 'name' => 'RolesView', 'is_administrative' => true]);
        $rolesRole->syncPermissionsSafe(CompanyPermission::whereIn('key', ['roles.view'])->pluck('id')->all());
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $rolesRole->id]);

        $this->as($u)->getJson('/api/company/people')->assertForbidden();      // pas de facette personne
        $this->as($u)->getJson('/api/company/roles')->assertOk();              // mais accès aux rôles
    }

    public function test_list_requires_a_facet(): void
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'nofacet', 'name' => 'NoFacet', 'is_administrative' => false]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', ['support.view'])->pluck('id')->all());
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $this->as($u)->getJson('/api/company/people')->assertForbidden();
    }
}
