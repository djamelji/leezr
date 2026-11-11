<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentAccessPolicy;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-758 (DMS M1.1) — Employee sans compte via sujet, policy contextuelle par catégorie,
 * redaction des métadonnées, versions Company. DEV uniquement.
 */
class DmsM11FoundationsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;
    private $memberB;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create([
            'name' => 'M11 Co', 'slug' => 'm11-co',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);

        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);

        $this->userB = User::factory()->create();
        $this->memberB = $this->company->memberships()->create(['user_id' => $this->userB->id, 'role' => 'user', 'company_role_id' => $adminRole->id]);

        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create(['company_id' => $this->company->id, 'document_type_id' => $type->id, 'enabled' => true, 'order' => $i * 10]);
        }
    }

    private function userWithPerms(array $permKeys, bool $admin = false): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.$user->id, 'name' => 'R', 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permKeys)->pluck('id')->toArray());
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $user;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function pdf(string $name, string $marker = 'A'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nM11[{$marker}]\n".str_repeat('x', 48)."\n%%EOF");
    }

    private function accountlessEmployee(): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => null,
            'first_name' => 'No', 'last_name' => 'Account', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);
    }

    private function future(): string
    {
        return now()->addYear()->toDateString();
    }

    // ───────── Employee SANS compte : parcours complet par sujet ─────────

    public function test_accountless_employee_full_document_journey(): void
    {
        $emp = $this->accountlessEmployee();
        $ref = "employee-{$emp->id}";

        // Liste (aucun compte requis)
        $this->as($this->owner)->getJson("/api/company/people/{$ref}/documents")->assertOk();

        // Upload administratif
        $this->as($this->owner)->postJson("/api/company/people/{$ref}/documents/id_card", [
            'file' => $this->pdf('id.pdf', 'V1'), 'expires_at' => $this->future(),
        ])->assertOk();

        // Stocké SANS user_id, rattaché au sujet employee
        $doc = MemberDocument::where('company_id', $this->company->id)
            ->where('document_subject_type', 'employee')->where('document_subject_id', $emp->id)->first();
        $this->assertNotNull($doc);
        $this->assertNull($doc->user_id);
        $this->assertNotNull($doc->file_sha256);
        $oldPath = $doc->file_path;

        // Remplacement non destructif
        $this->as($this->owner)->postJson("/api/company/people/{$ref}/documents/id_card", [
            'file' => $this->pdf('id.pdf', 'V2'), 'expires_at' => $this->future(),
        ])->assertOk();
        Storage::disk('local')->assertExists($oldPath); // ancienne version conservée

        $versions = $this->as($this->owner)->getJson("/api/company/people/{$ref}/documents/id_card/versions")->assertOk()->json('versions');
        $this->assertCount(1, $versions);

        // Téléchargement
        $this->as($this->owner)->get("/api/company/people/{$ref}/documents/id_card/download")->assertOk();

        // Un seul document courant (upsert par sujet)
        $this->assertSame(1, MemberDocument::where('document_subject_type', 'employee')->where('document_subject_id', $emp->id)->count());
    }

    public function test_accountless_employee_tenant_isolation(): void
    {
        $emp = $this->accountlessEmployee();
        $this->as($this->owner)->postJson("/api/company/people/employee-{$emp->id}/documents/id_card", [
            'file' => $this->pdf('id.pdf'), 'expires_at' => $this->future(),
        ])->assertOk();

        // Autre société + owner
        $other = Company::create(['name' => 'Other', 'slug' => 'other-m11', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($other);
        $otherRole = $this->setUpCompanyRbac($other);
        $outsider = User::factory()->create();
        $other->memberships()->create(['user_id' => $outsider->id, 'role' => 'owner', 'company_role_id' => $otherRole->id]);

        $this->actingAs($outsider)->withHeaders(['X-Company-Id' => $other->id])
            ->getJson("/api/company/people/employee-{$emp->id}/documents/id_card/download")->assertNotFound();
    }

    // ───────── Policy contextuelle par catégorie (view_confidential ≠ passe universel) ─────────

    public function test_view_confidential_covers_identity_not_payroll(): void
    {
        $policy = app(DocumentAccessPolicy::class);
        $viewer = $this->userWithPerms(['documents.view', 'documents.view_confidential'], admin: true);
        $subject = DocumentSubject::user($this->userB->id);

        // id_card (identité) → autorisé
        $this->assertTrue($policy->canAccess($viewer, $this->company, $subject, 'id_card'));
        // payslip (paie) → REFUSÉ (domaine paie, pas view_confidential)
        $this->assertFalse($policy->canAccess($viewer, $this->company, $subject, 'payslip'));
    }

    public function test_payroll_manager_covers_payslip_not_identity(): void
    {
        $policy = app(DocumentAccessPolicy::class);
        $payroll = $this->userWithPerms(['documents.view', 'workforce.compensation_read'], admin: true);
        $subject = DocumentSubject::user($this->userB->id);

        $this->assertTrue($policy->canAccess($payroll, $this->company, $subject, 'payslip'));
        $this->assertFalse($policy->canAccess($payroll, $this->company, $subject, 'id_card'));
    }

    public function test_sensitive_read_covers_identity(): void
    {
        $policy = app(DocumentAccessPolicy::class);
        $hr = $this->userWithPerms(['documents.view', 'workforce.sensitive_read'], admin: true);
        $subject = DocumentSubject::user($this->userB->id);

        $this->assertTrue($policy->canAccess($hr, $this->company, $subject, 'id_card'));
        $this->assertFalse($policy->canAccess($hr, $this->company, $subject, 'payslip'));
    }

    public function test_plain_view_covers_only_standard(): void
    {
        $policy = app(DocumentAccessPolicy::class);
        $viewer = $this->userWithPerms(['documents.view']);
        $subject = DocumentSubject::user($this->userB->id);

        $this->assertTrue($policy->canAccess($viewer, $this->company, $subject, 'driving_license')); // standard
        $this->assertFalse($policy->canAccess($viewer, $this->company, $subject, 'id_card'));
        $this->assertFalse($policy->canAccess($viewer, $this->company, $subject, 'payslip'));
    }

    // ───────── Relation manager direct (vérifiée via manager_id) ─────────

    public function test_team_manager_accesses_reports_standard_not_confidential(): void
    {
        $policy = app(DocumentAccessPolicy::class);

        // Manager (compte) + son subordonné direct
        $managerUser = $this->userWithPerms(['documents.view']);
        $managerEmp = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $managerUser->id,
            'first_name' => 'Mgr', 'last_name' => 'Direct', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'M-'.uniqid(),
        ]);
        $report = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => null, 'manager_id' => $managerEmp->id,
            'first_name' => 'Rep', 'last_name' => 'Ort', 'status' => Employee::STATUS_ACTIVE,
            'hire_date' => '2026-01-01', 'employee_number' => 'R-'.uniqid(),
        ]);
        $reportSubject = DocumentSubject::employee($report->id);

        // Standard d'un subordonné direct → autorisé (relation vérifiée)
        $this->assertTrue($policy->canAccess($managerUser, $this->company, $reportSubject, 'driving_license'));
        // Pièces confidentielles → NON (la relation n'escalade pas)
        $this->assertFalse($policy->canAccess($managerUser, $this->company, $reportSubject, 'payslip'));
        $this->assertFalse($policy->canAccess($managerUser, $this->company, $reportSubject, 'id_card'));

        // Un non-manager (même perms) n'accède pas au standard d'un employé qu'il ne gère pas
        $stranger = $this->userWithPerms([]);
        $this->assertFalse($policy->canAccess($stranger, $this->company, $reportSubject, 'driving_license'));
    }

    // ───────── Redaction des métadonnées ─────────

    public function test_metadata_redaction_hides_others_confidential(): void
    {
        // Documents du membre B
        $this->as($this->owner)->postJson("/api/company/members/{$this->memberB->id}/documents/id_card", ['file' => $this->pdf('id.pdf'), 'expires_at' => $this->future()])->assertOk();
        $this->as($this->owner)->postJson("/api/company/members/{$this->memberB->id}/documents/driving_license", ['file' => $this->pdf('dl.pdf')])->assertOk();

        $manager = $this->userWithPerms(['documents.view']);
        $docs = collect($this->as($manager)->getJson("/api/company/members/{$this->memberB->id}/documents")->json('documents'))->keyBy('code');

        // id_card (confidentiel d'autrui) : existence + métadonnées MASQUÉES
        $this->assertTrue($docs['id_card']['restricted'] ?? false);
        $this->assertFalse($docs['id_card']['has_file']);
        $this->assertNull($docs['id_card']['upload']);
        // driving_license (standard) : visible normalement
        $this->assertTrue($docs['driving_license']['has_file']);
        $this->assertNotNull($docs['driving_license']['upload']);
    }

    // ───────── Versions Company ─────────

    public function test_company_versions_exposed_with_author_and_hash(): void
    {
        $this->as($this->owner)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('kbis.pdf', 'C1')])->assertOk();
        $this->as($this->owner)->postJson('/api/company/documents/kbis', ['file' => $this->pdf('kbis.pdf', 'C2')])->assertOk();

        $res = $this->as($this->owner)->getJson('/api/company/documents/kbis/versions')->assertOk()->json();
        $this->assertNotNull($res['current']['file_sha256']);
        $this->assertCount(1, $res['versions']);
        $this->assertNotNull($res['versions'][0]['file_sha256']);
        $this->assertSame($this->owner->name, $res['versions'][0]['author']);
        $this->assertArrayNotHasKey('file_path', $res['versions'][0]); // chemin non exposé

        $versionId = $res['versions'][0]['id'];
        $this->as($this->owner)->get("/api/company/documents/kbis/versions/{$versionId}/download")->assertOk();
    }
}
