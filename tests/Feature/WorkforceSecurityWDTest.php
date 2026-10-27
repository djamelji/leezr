<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\GeneratedDocument;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-634 (Workforce 2.0 / W-D) — sécurisation RBAC : masquage serveur du salaire, scope manager/self
 * sur congés/pointages, téléchargement documentaire authentifié + scopé, isolation tenant.
 */
class WorkforceSecurityWDTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $manager;
    private Employee $report;
    private Employee $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_documents']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_documents', 'name' => 'Workforce Documents', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'WD Co', 'slug' => 'wd-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_documents'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        // Employés : un manager, son rapport direct, un inconnu.
        $this->manager = $this->employee('Mona', 'Manager', null);
        $this->report = $this->employee('Remy', 'Report', $this->manager->id);
        $this->stranger = $this->employee('Sam', 'Stranger', null);

        // Contrat + salaire sur le report (pour tester le masquage).
        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->report->id,
            'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35,
            'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true,
        ]);
        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'contract_id' => $contract->id,
            'base_salary_cents' => 500000, 'currency' => 'EUR', 'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500, 'effective_from' => '2026-01-01', 'compensation_type' => 'monthly',
        ]);
    }

    private function employee(string $first, string $last, ?int $managerId, ?int $userId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $userId,
            'first_name' => $first, 'last_name' => $last, 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'manager_id' => $managerId,
            'employee_number' => 'E-'.uniqid(),
        ]);
    }

    /** Crée un user + role(perms) + membership ; lie à un Employee si fourni. */
    private function userWith(array $permKeys, bool $admin, ?Employee $asEmployee = null): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permKeys)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
        if ($asEmployee) {
            $asEmployee->update(['user_id' => $user->id]);
        }

        return $user;
    }

    private function getApi(User $u, string $path, ?int $companyId = null)
    {
        return $this->actingAs($u)->getJson('/api'.$path, ['X-Company-Id' => $companyId ?? $this->company->id]);
    }

    // ── Salaire ──
    public function test_salary_hidden_without_compensation_read(): void
    {
        $noComp = $this->userWith(['workforce.view', 'workforce.manage'], true);
        $res = $this->getApi($noComp, "/workforce/employees/{$this->report->id}");
        $res->assertOk();
        $this->assertStringNotContainsString('base_salary_cents', $res->getContent(), 'salaire masqué sans compensation_read');
    }

    public function test_salary_visible_with_compensation_read(): void
    {
        $hr = $this->userWith(['workforce.view', 'workforce.manage', 'workforce.compensation_read'], true);
        $res = $this->getApi($hr, "/workforce/employees/{$this->report->id}");
        $res->assertOk();
        $this->assertStringContainsString('base_salary_cents', $res->getContent(), 'salaire visible avec compensation_read');
    }

    public function test_owner_sees_salary(): void
    {
        $owner = User::factory()->create();
        Membership::create(['user_id' => $owner->id, 'company_id' => $this->company->id, 'role' => 'owner']);
        $res = $this->getApi($owner, "/workforce/employees/{$this->report->id}");
        $res->assertOk()->assertSee('base_salary_cents');
    }

    // ── Scope congés ──
    public function test_employee_cannot_read_others_leave(): void
    {
        $emp = $this->userWith(['workforce.leave_request'], false, $this->stranger);
        $this->getApi($emp, "/workforce/leaves?employee_id={$this->report->id}")->assertForbidden();
    }

    public function test_employee_can_read_own_leave(): void
    {
        $emp = $this->userWith(['workforce.leave_request'], false, $this->stranger);
        $this->getApi($emp, "/workforce/leaves?employee_id={$this->stranger->id}")->assertOk();
    }

    public function test_manager_can_read_direct_report_leave(): void
    {
        $mgr = $this->userWith(['workforce.leave_request'], false, $this->manager);
        $this->getApi($mgr, "/workforce/leaves?employee_id={$this->report->id}")->assertOk();
        // mais pas un inconnu
        $this->getApi($mgr, "/workforce/leaves?employee_id={$this->stranger->id}")->assertForbidden();
    }

    public function test_approval_queue_requires_view_all(): void
    {
        $emp = $this->userWith(['workforce.leave_request'], false, $this->stranger);
        $this->getApi($emp, '/workforce/leaves')->assertForbidden();

        $approver = $this->userWith(['workforce.leave_request', 'workforce.leave_approve'], true);
        $this->getApi($approver, '/workforce/leaves')->assertOk();
    }

    // ── Scope pointages ──
    public function test_employee_cannot_read_others_time(): void
    {
        $emp = $this->userWith(['workforce.timesheet_view'], false, $this->stranger);
        $this->getApi($emp, "/workforce/time-entries?employee_id={$this->report->id}")->assertForbidden();
        $this->getApi($emp, "/workforce/time-entries?employee_id={$this->stranger->id}")->assertOk();
    }

    // ── Isolation tenant ──
    public function test_cross_tenant_denied(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $other->id, 'role' => 'owner']);
        // header d'une autre company → pas d'accès à notre company (module non actif → 403, ou 404)
        $res = $this->actingAs($u)->getJson("/api/workforce/employees/{$this->report->id}", ['X-Company-Id' => $other->id]);
        $this->assertContains($res->status(), [403, 404], 'aucun accès cross-tenant');
    }

    // ── Téléchargement documentaire authentifié + scopé ──
    public function test_document_download_scoped_and_authenticated(): void
    {
        Storage::fake('local');
        $path = "documents/generated/{$this->company->id}/doc.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $template = \App\Core\Documents\DocumentTemplate::create([
            'company_id' => null, 'code' => 'tpl-'.uniqid(), 'name' => 'T', 'subject_type' => 'employee',
            'body_html' => '<p>x</p>', 'variables_schema' => [], 'version' => 1, 'is_system' => true, 'is_active' => true,
        ]);
        $doc = GeneratedDocument::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'document_template_id' => $template->id,
            'subject_type' => 'employee', 'subject_id' => $this->report->id,
            'template_version' => 1, 'generated_at' => now(), 'file_path' => $path, 'signature_status' => 'none',
            'generated_by' => User::factory()->create()->id, 'generation_snapshot' => [], 'idempotency_key' => 'k-'.uniqid(),
        ]);

        // RH (view+manage+documents.view) → autorisé.
        $hr = $this->userWith(['workforce.view', 'workforce.manage', 'workforce_documents.view'], true);
        $this->getApi($hr, "/workforce/documents/{$doc->id}/download")->assertOk();

        // Un collaborateur sans lien avec le report → 403 (scope collaborateur).
        $other = $this->userWith(['workforce_documents.view'], false, $this->stranger);
        $this->getApi($other, "/workforce/documents/{$doc->id}/download")->assertForbidden();
    }

    // ── Compatibilité : la migration accorde les permissions fines aux rôles légitimes ──
    public function test_compat_migration_grants_fine_permissions(): void
    {
        $adminView = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'adm-view', 'name' => 'AdmView', 'is_system' => false, 'is_administrative' => true]);
        $adminView->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.view', 'workforce.leave_approve'])->pluck('id')->toArray());

        (require database_path('migrations/2026_10_27_200000_grant_workforce_fine_permissions_compat.php'))->up();

        $this->assertTrue($adminView->fresh()->hasPermission('workforce.compensation_read'), 'view admin → compensation_read');
        $this->assertTrue($adminView->fresh()->hasPermission('workforce.leave_view_all'), 'leave_approve → leave_view_all');
    }
}
