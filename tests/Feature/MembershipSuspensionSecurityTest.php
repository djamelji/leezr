<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRODUCT-SECURITY-1 (ADR-688) — suspension/révocation d'accès RÉELLE côté serveur.
 * La suspension bloque TOUS les endpoints company (gate SetCompanyContext), est isolée
 * par tenant, protège owner/soi/dernier admin, et ne détruit aucune donnée RH.
 */
class MembershipSuspensionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User $owner;
    private User $member;        // salarié de A (cible), aussi membre de B
    private Employee $employeeA;
    private CompanyRole $roleA;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'W', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->companyA = $this->makeCompany('sec-a');
        $this->companyB = $this->makeCompany('sec-b');

        $this->owner = User::factory()->create();
        Membership::create(['user_id' => $this->owner->id, 'company_id' => $this->companyA->id, 'role' => 'owner']);

        // Rôle collaborateur (non-admin) dans A.
        $this->roleA = CompanyRole::create(['company_id' => $this->companyA->id, 'key' => 'emp-a', 'name' => 'Collab', 'is_system' => false, 'is_administrative' => false]);

        // Member : user + membership A (via un rôle non-admin) + Employee lié + membership B.
        $this->member = User::factory()->create(['password_set_at' => now(), 'last_login_at' => now()]);
        Membership::create(['user_id' => $this->member->id, 'company_id' => $this->companyA->id, 'role' => 'user', 'company_role_id' => $this->roleA->id]);
        $roleB = CompanyRole::create(['company_id' => $this->companyB->id, 'key' => 'emp-b', 'name' => 'Collab', 'is_system' => false, 'is_administrative' => false]);
        Membership::create(['user_id' => $this->member->id, 'company_id' => $this->companyB->id, 'role' => 'user', 'company_role_id' => $roleB->id]);

        $this->employeeA = Employee::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'user_id' => $this->member->id, 'first_name' => 'Max', 'last_name' => 'Member', 'employee_number' => 'EA', 'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE]);

        Model::reguard();
    }

    private function makeCompany(string $slug): Company
    {
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);

        return $c;
    }

    private function api(User $u, string $method, string $path, Company $company, array $body = [])
    {
        return $this->actingAs($u)->json($method, '/api'.$path, $body, ['X-Company-Id' => $company->id]);
    }

    private function suspend(): void
    {
        $this->api($this->owner, 'POST', "/workforce/employees/{$this->employeeA->id}/access/suspend", $this->companyA, ['reason' => 'offboarding'])->assertOk();
    }

    // ───────────────── Enforcement réel ─────────────────

    public function test_suspended_membership_blocks_all_company_endpoints(): void
    {
        // Avant : le member accède à un endpoint company (gated membership seul).
        $this->api($this->member, 'GET', '/nav', $this->companyA)->assertOk();

        $this->suspend();

        // Après : 403 sur N'IMPORTE quel endpoint company (gate SetCompanyContext), effet immédiat.
        $this->api($this->member, 'GET', '/nav', $this->companyA)
            ->assertForbidden()
            ->assertJson(['code' => 'membership_suspended']);
        // Self-service aussi bloqué.
        $this->api($this->member, 'GET', '/workforce/me', $this->companyA)->assertForbidden();
    }

    public function test_restore_reenables_access(): void
    {
        $this->suspend();
        $this->api($this->member, 'GET', '/nav', $this->companyA)->assertForbidden();

        $this->api($this->owner, 'POST', "/workforce/employees/{$this->employeeA->id}/access/restore", $this->companyA)->assertOk();
        $this->api($this->member, 'GET', '/nav', $this->companyA)->assertOk();
    }

    public function test_suspension_is_tenant_isolated(): void
    {
        $this->suspend();
        // Suspendu dans A, mais l'accès à B (même user) reste intact.
        $this->api($this->member, 'GET', '/nav', $this->companyA)->assertForbidden();
        $this->api($this->member, 'GET', '/nav', $this->companyB)->assertOk();
    }

    // ───────────────── Sécurité admin ─────────────────

    public function test_cannot_suspend_owner(): void
    {
        $ownerEmp = Employee::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'user_id' => $this->owner->id, 'first_name' => 'O', 'last_name' => 'W', 'employee_number' => 'OW', 'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE]);
        $this->api($this->owner, 'POST', "/workforce/employees/{$ownerEmp->id}/access/suspend", $this->companyA)->assertStatus(422);
        $this->api($this->owner, 'GET', '/nav', $this->companyA)->assertOk(); // owner jamais bloqué
    }

    public function test_cannot_self_suspend(): void
    {
        // Un admin (non-owner) avec members.manage tente de se suspendre lui-même.
        $adminRole = CompanyRole::create(['company_id' => $this->companyA->id, 'key' => 'adm', 'name' => 'Admin', 'is_system' => false, 'is_administrative' => true]);
        $adminRole->permissions()->sync(CompanyPermission::whereIn('key', ['members.manage'])->pluck('id')->toArray());
        $admin = User::factory()->create();
        Membership::create(['user_id' => $admin->id, 'company_id' => $this->companyA->id, 'role' => 'user', 'company_role_id' => $adminRole->id]);
        $adminEmp = Employee::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'user_id' => $admin->id, 'first_name' => 'A', 'last_name' => 'D', 'employee_number' => 'AD', 'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE]);

        $this->api($admin, 'POST', "/workforce/employees/{$adminEmp->id}/access/suspend", $this->companyA)->assertStatus(422);
    }

    public function test_non_owner_admin_can_be_suspended_when_owner_remains(): void
    {
        $adminRole = CompanyRole::create(['company_id' => $this->companyA->id, 'key' => 'adm2', 'name' => 'Admin', 'is_system' => false, 'is_administrative' => true]);
        $admin = User::factory()->create();
        Membership::create(['user_id' => $admin->id, 'company_id' => $this->companyA->id, 'role' => 'user', 'company_role_id' => $adminRole->id]);
        $adminEmp = Employee::withoutGlobalScopes()->create(['company_id' => $this->companyA->id, 'user_id' => $admin->id, 'first_name' => 'A', 'last_name' => 'D', 'employee_number' => 'AD2', 'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE]);

        // L'owner reste administrateur → suspendre l'autre admin est autorisé.
        $this->api($this->owner, 'POST', "/workforce/employees/{$adminEmp->id}/access/suspend", $this->companyA)->assertOk();
        $this->api($admin, 'GET', '/nav', $this->companyA)->assertForbidden();
    }

    public function test_access_lifecycle_requires_members_manage(): void
    {
        // Un acteur avec members.invite mais PAS members.manage ne peut pas suspendre.
        $weakRole = CompanyRole::create(['company_id' => $this->companyA->id, 'key' => 'weak', 'name' => 'Weak', 'is_system' => false, 'is_administrative' => true]);
        $weakRole->permissions()->sync(CompanyPermission::whereIn('key', ['members.invite'])->pluck('id')->toArray());
        $weak = User::factory()->create();
        Membership::create(['user_id' => $weak->id, 'company_id' => $this->companyA->id, 'role' => 'user', 'company_role_id' => $weakRole->id]);

        $this->api($weak, 'POST', "/workforce/employees/{$this->employeeA->id}/access/suspend", $this->companyA)->assertForbidden();
    }

    // ───────────────── Intégrité données & révocation ─────────────────

    public function test_revoke_removes_access_but_preserves_data(): void
    {
        $this->api($this->owner, 'POST', "/workforce/employees/{$this->employeeA->id}/access/revoke", $this->companyA, ['reason' => 'départ'])->assertOk();

        // Membership A supprimée ; mais Employee + User conservés ; lien délié.
        $this->assertDatabaseMissing('memberships', ['user_id' => $this->member->id, 'company_id' => $this->companyA->id]);
        $this->assertDatabaseHas('workforce_employees', ['id' => $this->employeeA->id, 'user_id' => null]);
        $this->assertDatabaseHas('users', ['id' => $this->member->id]);
        // Accès company A coupé.
        $this->api($this->member, 'GET', '/nav', $this->companyA)->assertForbidden();
        // Mais le User garde son accès à B (autres companies intactes).
        $this->api($this->member, 'GET', '/nav', $this->companyB)->assertOk();
    }

    // ───────────────── Audit ─────────────────

    public function test_audit_trail_written(): void
    {
        $this->suspend();
        $this->assertTrue(
            CompanyAuditLog::where('company_id', $this->companyA->id)->where('action', 'member.suspended')->exists(),
            'un log member.suspended doit être écrit',
        );
    }
}
