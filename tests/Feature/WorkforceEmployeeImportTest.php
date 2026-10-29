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
use App\Core\Workforce\Department;
use App\Core\Workforce\Employee;
use App\Core\Workforce\JobRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-G2 (ADR-642) — import CSV de nouveaux collaborateurs : dry-run sans écriture, dédup par clé
 * métier, validation (dates/dept/poste/manager), application transactionnelle idempotente, RBAC,
 * isolation tenant, non-régression des dossiers existants.
 */
class WorkforceEmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Department $dept;
    private JobRole $role;
    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Imp Co', 'slug' => 'imp-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'workforce', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);

        $this->dept = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Logistique', 'sort_order' => 0]);
        $this->role = JobRole::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'title' => 'Préparateur']);
        $this->manager = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => 'Mona', 'last_name' => 'Manager',
            'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'EMP-001',
        ]);
    }

    private function importer(array $perms): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $user;
    }

    private const MAPPING = [
        'first_name' => 'first_name', 'last_name' => 'last_name', 'employee_number' => 'employee_number',
        'email' => 'email', 'hire_date' => 'hire_date', 'status' => 'status',
        'department' => 'department', 'job_role' => 'job_role', 'manager' => 'manager',
    ];

    private function csv(array $dataLines): string
    {
        $header = 'first_name,last_name,employee_number,email,hire_date,status,department,job_role,manager';

        return $header."\n".implode("\n", $dataLines);
    }

    private function postApi(User $u, string $path, array $body)
    {
        return $this->actingAs($u)->postJson('/api'.$path, $body, ['X-Company-Id' => $this->company->id]);
    }

    public function test_preview_is_dry_run_with_no_writes(): void
    {
        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->csv([
            'Marie,Durand,EMP-100,marie@example.com,2026-01-15,active,Logistique,Préparateur,EMP-001',
            'Paul,Martin,EMP-101,paul@example.com,2026-02-01,active,Logistique,Préparateur,',
        ]);

        $before = Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
        $res = $this->postApi($u, '/workforce/employees/import/preview', ['content' => $csv, 'mapping' => self::MAPPING])->assertOk();

        $this->assertSame(2, $res->json('counts.create'));
        $this->assertSame(0, $res->json('counts.invalid'));
        $this->assertSame($before, Employee::withoutGlobalScopes()->where('company_id', $this->company->id)->count(), 'dry-run ne crée rien');
    }

    public function test_apply_creates_only_valid_new_rows(): void
    {
        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->csv([
            'Marie,Durand,EMP-100,marie@example.com,2026-01-15,active,Logistique,Préparateur,EMP-001',
            'Paul,Martin,EMP-101,paul@example.com,bad-date,active,Logistique,Préparateur,',   // date invalide
            'Lea,Bernard,EMP-102,lea@example.com,2026-03-01,active,Inconnu,Préparateur,',       // dept inconnu
        ]);

        $res = $this->postApi($u, '/workforce/employees/import', ['content' => $csv, 'mapping' => self::MAPPING])->assertCreated();
        $this->assertSame(1, $res->json('created'));
        $this->assertSame(2, $res->json('skipped'));

        $this->assertDatabaseHas('workforce_employees', ['company_id' => $this->company->id, 'employee_number' => 'EMP-100', 'department_id' => $this->dept->id, 'job_role_id' => $this->role->id, 'manager_id' => $this->manager->id]);
        $this->assertDatabaseMissing('workforce_employees', ['employee_number' => 'EMP-101']);
        $this->assertDatabaseMissing('workforce_employees', ['employee_number' => 'EMP-102']);
    }

    public function test_duplicate_by_business_key_is_skipped_never_overwritten(): void
    {
        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        // EMP-001 existe déjà (le manager). Import tente de le "réimporter" avec un autre prénom.
        $csv = $this->csv(['Hacker,Overwrite,EMP-001,x@example.com,2026-01-15,active,Logistique,Préparateur,']);

        $res = $this->postApi($u, '/workforce/employees/import', ['content' => $csv, 'mapping' => self::MAPPING])->assertCreated();
        $this->assertSame(0, $res->json('created'));
        $this->assertSame(1, $res->json('skipped'));
        // Le dossier existant n'a PAS été écrasé.
        $this->assertSame('Mona', Employee::withoutGlobalScopes()->where('employee_number', 'EMP-001')->value('first_name'));
    }

    public function test_apply_is_idempotent(): void
    {
        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->csv(['Marie,Durand,EMP-100,marie@example.com,2026-01-15,active,Logistique,Préparateur,']);

        $this->postApi($u, '/workforce/employees/import', ['content' => $csv, 'mapping' => self::MAPPING])->assertCreated()->assertJson(['created' => 1]);
        // Re-soumission du même fichier : rien de nouveau (clé métier EMP-100 déjà présente).
        $res2 = $this->postApi($u, '/workforce/employees/import', ['content' => $csv, 'mapping' => self::MAPPING])->assertCreated();
        $this->assertSame(0, $res2->json('created'));
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('employee_number', 'EMP-100')->count());
    }

    public function test_ambiguous_manager_is_flagged_as_conflict(): void
    {
        // Deux collaborateurs homonymes sans référence → résolution par nom ambiguë.
        Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Jean', 'last_name' => 'Dupont', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'EMP-200']);
        Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Jean', 'last_name' => 'Dupont', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => 'EMP-201']);

        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->csv(['Marie,Durand,EMP-100,marie@example.com,2026-01-15,active,Logistique,Préparateur,Jean Dupont']);

        $res = $this->postApi($u, '/workforce/employees/import/preview', ['content' => $csv, 'mapping' => self::MAPPING])->assertOk();
        $this->assertSame(1, $res->json('counts.conflict'));
        $this->assertSame(0, $res->json('counts.create'));
    }

    public function test_malformed_csv_is_rejected(): void
    {
        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $this->postApi($u, '/workforce/employees/import/preview', ['content' => 'only-a-header-no-rows', 'mapping' => self::MAPPING])->assertStatus(422);
    }

    public function test_requires_import_permission(): void
    {
        $u = $this->importer(['workforce.view', 'workforce.manage']); // pas workforce.import
        $csv = $this->csv(['Marie,Durand,EMP-100,marie@example.com,2026-01-15,active,Logistique,Préparateur,']);
        $this->postApi($u, '/workforce/employees/import', ['content' => $csv, 'mapping' => self::MAPPING])->assertForbidden();
    }

    public function test_tenant_isolation_resolves_only_own_departments(): void
    {
        // Un département du même nom dans une autre Company ne doit pas être résolu.
        $other = Company::create(['name' => 'Other', 'slug' => 'other-imp', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        Department::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Commercial', 'sort_order' => 0]);

        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->csv(['Marie,Durand,EMP-100,marie@example.com,2026-01-15,active,Commercial,Préparateur,']);
        $res = $this->postApi($u, '/workforce/employees/import/preview', ['content' => $csv, 'mapping' => self::MAPPING])->assertOk();
        $this->assertSame(1, $res->json('counts.invalid'), 'département d’une autre Company non résolu → ligne invalide');
    }

    public function test_row_without_business_key_is_blocked_not_silently_created(): void
    {
        // W-G2 réserve : sans employee_number, l'identité n'est pas stable → ligne BLOQUÉE (jamais
        // créée), donc jamais re-créable au second import. Idempotence réellement garantie.
        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->csv(['Sans,Reference,,sans@example.com,2026-01-15,active,Logistique,Préparateur,']);

        $preview = $this->postApi($u, '/workforce/employees/import/preview', ['content' => $csv, 'mapping' => self::MAPPING])->assertOk();
        $this->assertSame(0, $preview->json('counts.create'));
        $this->assertSame(1, $preview->json('counts.invalid'));

        $res = $this->postApi($u, '/workforce/employees/import', ['content' => $csv, 'mapping' => self::MAPPING])->assertCreated();
        $this->assertSame(0, $res->json('created'));
        $this->assertDatabaseMissing('workforce_employees', ['first_name' => 'Sans', 'last_name' => 'Reference']);
    }

    public function test_does_not_create_user_contract_or_compensation(): void
    {
        $u = $this->importer(['workforce.view', 'workforce.manage', 'workforce.import']);
        $csv = $this->csv(['Marie,Durand,EMP-100,marie@example.com,2026-01-15,active,Logistique,Préparateur,']);
        $this->postApi($u, '/workforce/employees/import', ['content' => $csv, 'mapping' => self::MAPPING])->assertCreated();

        $emp = Employee::withoutGlobalScopes()->where('employee_number', 'EMP-100')->first();
        $this->assertNull($emp->user_id, 'aucun compte utilisateur créé');
        $this->assertSame(0, \App\Core\Workforce\EmploymentContract::withoutGlobalScopes()->where('employee_id', $emp->id)->count(), 'aucun contrat créé');
    }
}
