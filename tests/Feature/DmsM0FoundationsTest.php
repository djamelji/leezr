<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentLifecycleService;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentComplianceStateService as State;
use App\Core\Documents\Services\DocumentIntegrityService;
use App\Core\Documents\Services\DocumentVersioningService;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Core\People\PersonRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-756 (DMS M0) — socle documentaire : multi-tenant, sujet/PersonRef, intégrité/versioning,
 * état multi-dimensionnel (conformité consolidée), sécurité, compatibilité des anciennes routes.
 */
class DmsM0FoundationsTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $companyA;
    private Company $companyB;
    private User $adminA;
    private User $adminB;
    private $memberA;      // membership cible dans A
    private $memberB;      // membership cible dans B
    private User $limitedUser; // employé de A sans permission documents

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        [$this->companyA, $this->adminA, $this->memberA] = $this->makeCompany('co-a');
        [$this->companyB, $this->adminB, $this->memberB] = $this->makeCompany('co-b');

        // Employé de A avec un rôle SANS permission documents.*
        $this->limitedUser = User::factory()->create();
        $noDocRole = CompanyRole::create([
            'company_id' => $this->companyA->id,
            'key' => 'basic',
            'name' => 'Basic',
            'is_administrative' => false,
        ]);
        $noDocRole->permissions()->sync([]);
        $this->companyA->memberships()->create([
            'user_id' => $this->limitedUser->id,
            'role' => 'user',
            'company_role_id' => $noDocRole->id,
        ]);
    }

    /** @return array{0:Company,1:User,2:\App\Core\Models\Membership} */
    private function makeCompany(string $slug): array
    {
        $company = Company::create([
            'name' => strtoupper($slug),
            'slug' => $slug,
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
            'plan_key' => 'pro',
        ]);
        $this->activateCompanyModules($company);
        $adminRole = $this->setUpCompanyRbac($company);

        $admin = User::factory()->create();
        $company->memberships()->create([
            'user_id' => $admin->id,
            'role' => 'owner',
            'company_role_id' => $adminRole->id,
        ]);

        $target = User::factory()->create();
        $membership = $company->memberships()->create([
            'user_id' => $target->id,
            'role' => 'user',
            'company_role_id' => $adminRole->id,
        ]);

        foreach (DocumentType::where('is_system', true)->get() as $i => $type) {
            DocumentTypeActivation::create([
                'company_id' => $company->id,
                'document_type_id' => $type->id,
                'enabled' => true,
                'order' => $i * 10,
            ]);
        }

        return [$company, $admin, $membership];
    }

    private function asAdmin(User $admin, Company $company)
    {
        return $this->actingAs($admin)->withHeaders(['X-Company-Id' => $company->id]);
    }

    /**
     * Fichier PDF de test avec un CONTENU RÉEL (fake()->create() produit un fichier vide → tous
     * les hashs seraient identiques). Un `marker` distinct ⇒ contenu distinct ⇒ hash distinct.
     */
    private function pdf(string $name, string $marker = 'DEFAULT'): UploadedFile
    {
        $content = "%PDF-1.4\n% M0 test content [{$marker}]\n".str_repeat('x', 128)."\n%%EOF";

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function uploadUri($membership, string $code): string
    {
        return "/api/company/members/{$membership->id}/documents/{$code}";
    }

    // ───────────────────────── Multi-tenant ─────────────────────────

    public function test_company_a_cannot_read_company_b_documents(): void
    {
        // Admin B dépose un document pour son membre B
        $this->asAdmin($this->adminB, $this->companyB)
            ->postJson($this->uploadUri($this->memberB, 'id_card'), ['file' => $this->pdf('b.pdf')])
            ->assertOk();

        // Admin A, avec le contexte société A, ne peut pas atteindre un membre de B
        $this->asAdmin($this->adminA, $this->companyA)
            ->getJson($this->uploadUri($this->memberB, 'id_card').'/download')
            ->assertNotFound();
    }

    public function test_employee_a_cannot_read_employee_b_documents(): void
    {
        // Membre A possède un document
        $this->asAdmin($this->adminA, $this->companyA)
            ->postJson($this->uploadUri($this->memberA, 'id_card'), ['file' => $this->pdf('a.pdf')])
            ->assertOk();

        // Un employé lambda de A (sans documents.view) ne peut pas télécharger le doc d'un autre employé
        $this->actingAs($this->limitedUser)
            ->withHeaders(['X-Company-Id' => $this->companyA->id])
            ->getJson($this->uploadUri($this->memberA, 'id_card').'/download')
            ->assertForbidden();
    }

    public function test_download_refused_without_permission(): void
    {
        $this->asAdmin($this->adminA, $this->companyA)
            ->postJson($this->uploadUri($this->memberA, 'id_card'), ['file' => $this->pdf('a.pdf')])
            ->assertOk();

        $this->actingAs($this->limitedUser)
            ->withHeaders(['X-Company-Id' => $this->companyA->id])
            ->getJson($this->uploadUri($this->memberA, 'id_card').'/download')
            ->assertForbidden();

        // L'admin, lui, a documents.view
        $this->asAdmin($this->adminA, $this->companyA)
            ->getJson($this->uploadUri($this->memberA, 'id_card').'/download')
            ->assertOk();
    }

    // ───────────────────────── Sujet / PersonRef ─────────────────────────

    public function test_subject_employee_without_user(): void
    {
        $subject = DocumentSubject::employee(42);
        $this->assertTrue($subject->is(DocumentSubject::TYPE_EMPLOYEE));
        $this->assertSame(42, $subject->id);
        $this->assertSame('employee:42', (string) $subject);
    }

    public function test_subject_user_without_employee(): void
    {
        $doc = new MemberDocument(['user_id' => 7]);
        $subject = DocumentSubject::fromMemberDocument($doc);
        $this->assertTrue($subject->is(DocumentSubject::TYPE_USER));
        $this->assertSame(7, $subject->id);
    }

    public function test_adding_user_to_employee_does_not_change_subject(): void
    {
        // Document rattaché explicitement à un Employee ; un compte User (user_id) existe aussi.
        $doc = new MemberDocument([
            'document_subject_type' => DocumentSubject::TYPE_EMPLOYEE,
            'document_subject_id' => 99,
            'user_id' => 7,
        ]);

        $subject = DocumentSubject::fromMemberDocument($doc);

        // Le sujet RESTE employee:99 — l'ajout d'un compte ne le réaffecte jamais à user:7.
        $this->assertSame('employee:99', (string) $subject);
    }

    public function test_subject_is_personref_compatible(): void
    {
        $this->assertSame('employee:5', (string) DocumentSubject::fromPersonRef(PersonRef::forEmployee(5)));
        $this->assertSame('user:5', (string) DocumentSubject::fromPersonRef(PersonRef::forUser(5)));
    }

    // ───────────────────────── Intégrité / versioning ─────────────────────────

    public function test_upload_computes_correct_hash(): void
    {
        $this->asAdmin($this->adminA, $this->companyA)
            ->postJson($this->uploadUri($this->memberA, 'id_card'), ['file' => $this->pdf('a.pdf')])
            ->assertOk();

        $doc = MemberDocument::where('company_id', $this->companyA->id)->firstOrFail();
        $this->assertNotNull($doc->file_sha256);

        $integrity = app(DocumentIntegrityService::class);
        $this->assertTrue($integrity->verify($doc->file_path, $doc->file_sha256));
        $this->assertSame($integrity->hashOf($doc->file_path), $doc->file_sha256);
    }

    public function test_replacement_keeps_previous_version(): void
    {
        $uri = $this->uploadUri($this->memberA, 'id_card');

        $this->asAdmin($this->adminA, $this->companyA)->postJson($uri, ['file' => $this->pdf('v1.pdf', 'V1')])->assertOk();
        $doc = MemberDocument::where('company_id', $this->companyA->id)->firstOrFail();
        $firstPath = $doc->file_path;
        $firstHash = $doc->file_sha256;

        // Remplacement par un contenu DIFFÉRENT (marqueur différent → hash différent)
        $this->asAdmin($this->adminA, $this->companyA)->postJson($uri, ['file' => $this->pdf('v2.pdf', 'V2')])->assertOk();
        $doc->refresh();

        // Le document courant pointe vers le nouveau fichier
        $this->assertNotSame($firstPath, $doc->file_path);
        $this->assertNotSame($firstHash, $doc->file_sha256);

        // Une version précédente est conservée, avec l'ancien hash et l'ancien chemin
        $versions = app(DocumentVersioningService::class)->history($doc);
        $this->assertCount(1, $versions);
        $this->assertSame($firstHash, $versions->first()->file_sha256);
        $this->assertSame($firstPath, $versions->first()->file_path);

        // AUCUN fichier historique perdu : l'ancien fichier existe toujours sur le disque
        Storage::disk('local')->assertExists($firstPath);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_idempotent_replacement_with_identical_content(): void
    {
        $uri = $this->uploadUri($this->memberA, 'id_card');

        $this->asAdmin($this->adminA, $this->companyA)->postJson($uri, ['file' => $this->pdf('same.pdf', 'SAME')])->assertOk();
        $doc = MemberDocument::where('company_id', $this->companyA->id)->firstOrFail();
        $pathBefore = $doc->file_path;
        $hashBefore = $doc->file_sha256;

        // Ré-upload d'un fichier au contenu IDENTIQUE (même marqueur → même hash)
        $this->asAdmin($this->adminA, $this->companyA)->postJson($uri, ['file' => $this->pdf('same.pdf', 'SAME')])->assertOk();
        $doc->refresh();

        // Aucun changement : même fichier, aucune version créée
        $this->assertSame($pathBefore, $doc->file_path);
        $this->assertSame($hashBefore, $doc->file_sha256);
        $this->assertCount(0, app(DocumentVersioningService::class)->history($doc));
        $this->assertSame(1, MemberDocument::where('company_id', $this->companyA->id)->count());
    }

    // ───────────────────────── État multi-dimensionnel / conformité ─────────────────────────

    public function test_rejected_is_not_compliant(): void
    {
        $doc = new MemberDocument(['file_path' => 'x.pdf', 'published_state' => State::P_PUBLISHED, 'expires_at' => null]);
        $state = State::evaluate($doc, 'rejected');

        $this->assertSame(State::V_REJECTED, $state['validation']);
        $this->assertFalse($state['satisfies_obligation']);
    }

    public function test_pending_is_not_compliant(): void
    {
        $doc = new MemberDocument(['file_path' => 'x.pdf', 'published_state' => State::P_PUBLISHED, 'expires_at' => null]);
        $state = State::evaluate($doc, 'submitted');

        $this->assertSame(State::V_PENDING, $state['validation']);
        $this->assertFalse($state['satisfies_obligation']);
    }

    public function test_expired_is_not_compliant(): void
    {
        $doc = new MemberDocument(['file_path' => 'x.pdf', 'published_state' => State::P_PUBLISHED, 'expires_at' => now()->subDay()]);
        $state = State::evaluate($doc, 'approved');

        $this->assertSame(State::L_EXPIRED, $state['validity']);
        $this->assertFalse($state['satisfies_obligation']);
    }

    public function test_validated_and_not_expired_is_compliant(): void
    {
        $doc = new MemberDocument(['file_path' => 'x.pdf', 'published_state' => State::P_PUBLISHED, 'expires_at' => now()->addYear()]);
        $state = State::evaluate($doc, 'approved');

        $this->assertSame(State::V_APPROVED, $state['validation']);
        $this->assertSame(State::L_VALID, $state['validity']);
        $this->assertTrue($state['satisfies_obligation']);
    }

    public function test_missing_file_is_not_compliant(): void
    {
        $state = State::evaluate(null, null);
        $this->assertFalse($state['has_file']);
        $this->assertFalse($state['satisfies_obligation']);
    }

    // ───────────────────────── Compatibilité ─────────────────────────

    public function test_legacy_routes_remain_operational(): void
    {
        // Ancien contrat : index + upload + download fonctionnent toujours
        $this->asAdmin($this->adminA, $this->companyA)
            ->getJson("/api/company/members/{$this->memberA->id}/documents")
            ->assertOk();

        $this->asAdmin($this->adminA, $this->companyA)
            ->postJson($this->uploadUri($this->memberA, 'id_card'), ['file' => $this->pdf('legacy.pdf')])
            ->assertOk()
            ->assertJsonPath('document.code', 'id_card');

        $this->asAdmin($this->adminA, $this->companyA)
            ->getJson($this->uploadUri($this->memberA, 'id_card').'/download')
            ->assertOk();
    }
}
