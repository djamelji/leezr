<?php

namespace Tests\Feature;

use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipImportItem;
use App\Core\Documents\Payslip\PayslipImportService;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-762 (DMS M5) — import de bulletins : rapprochement SÛR + publication. Exigence absolue
 * prouvée : aucun bulletin publié dans le dossier d'un mauvais salarié.
 */
class DmsM5PayslipTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();

        $this->company = Company::create(['name' => 'M5 Co', 'slug' => 'm5-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
    }

    private function service(): PayslipImportService
    {
        return app(PayslipImportService::class);
    }

    private function employee(string $matricule, string $first = 'Jean', string $last = 'Dupont', ?int $userId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $userId,
            'first_name' => $first, 'last_name' => $last, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $matricule,
        ]);
    }

    private function batch(): PayslipImportBatch
    {
        return PayslipImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'review']);
    }

    private function segFile(string $marker = 'A'): string
    {
        $path = "payslip-imports/{$this->company->id}/seg-{$marker}-".uniqid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nSEG[{$marker}]\n%%EOF");

        return $path;
    }

    // ───────── Matching : déterministe, nom = revue ─────────

    public function test_exact_matricule_is_certain_and_publishable(): void
    {
        $emp = $this->employee('M-100');
        $item = $this->service()->addSegment($this->batch(), $this->segFile(), '1', "Matricule: M-100 Période: 2026-01");

        $this->assertSame(PayslipImportItem::C_CERTAIN, $item->match_confidence);
        $this->assertSame($emp->id, $item->matched_employee_id);
        $this->assertTrue($item->isPublishable());
    }

    public function test_name_only_is_probable_and_not_publishable(): void
    {
        $this->employee('M-200', 'Marie', 'Martin');
        $item = $this->service()->addSegment($this->batch(), $this->segFile(), '1', "Salarié: Marie Martin"); // pas de matricule

        $this->assertSame(PayslipImportItem::C_PROBABLE, $item->match_confidence);
        $this->assertSame(PayslipImportItem::S_REVIEW, $item->state);
        $this->assertFalse($item->isPublishable());
    }

    public function test_matricule_matching_is_strict(): void
    {
        // Le matricule est unique par société (contrainte DB) → un matricule inconnu ne retombe
        // JAMAIS sur un autre salarié par proximité.
        $this->employee('REAL-1', 'Vrai', 'Salarie');
        $item = $this->service()->addSegment($this->batch(), $this->segFile(), '1', "Matricule: WRONG-9");
        $this->assertSame(PayslipImportItem::C_NONE, $item->match_confidence);
        $this->assertNull($item->matched_employee_id);
    }

    public function test_homonyms_are_ambiguous(): void
    {
        $this->employee('H1', 'Jean', 'Dupont');
        $this->employee('H2', 'Jean', 'Dupont');
        $item = $this->service()->addSegment($this->batch(), $this->segFile(), '1', "Salarié: Jean Dupont");

        $this->assertSame(PayslipImportItem::C_AMBIGUOUS, $item->match_confidence);
        $this->assertFalse($item->isPublishable());
    }

    public function test_unknown_matricule_is_none(): void
    {
        $this->employee('KNOWN');
        $item = $this->service()->addSegment($this->batch(), $this->segFile(), '1', "Matricule: GHOST-999");

        $this->assertSame(PayslipImportItem::C_NONE, $item->match_confidence);
        $this->assertFalse($item->isPublishable());
    }

    // ───────── EXIGENCE ABSOLUE : jamais publié dans le mauvais dossier ─────────

    public function test_payslip_never_published_to_wrong_employee(): void
    {
        $empA = $this->employee('A-1', 'Alice', 'A');
        $empB = $this->employee('B-1', 'Bob', 'B');
        $batch = $this->batch();

        // Un segment matricule A-1 (certain), un segment nom seul (probable → revue), un ambigu (homonymes)
        $this->service()->addSegment($batch, $this->segFile('a'), '1', "Matricule: A-1 Période: 2026-02");
        $this->service()->addSegment($batch, $this->segFile('b'), '2', "Salarié: Bob B"); // probable, PAS publié
        $this->employee('H-1', 'Jean', 'Homonyme'); $this->employee('H-2', 'Jean', 'Homonyme');
        $this->service()->addSegment($batch, $this->segFile('c'), '3', "Salarié: Jean Homonyme"); // ambigu, PAS publié

        $this->service()->publishBatch($batch, $this->owner);

        // SEUL le bulletin certain est publié, rattaché à empA — rien pour empB, rien d'ambigu.
        $this->assertSame(1, PublishedPayslip::where('company_id', $this->company->id)->count());
        $pub = PublishedPayslip::first();
        $this->assertSame('employee', $pub->document_subject_type);
        $this->assertSame($empA->id, $pub->document_subject_id);
        $this->assertSame(0, PublishedPayslip::where('document_subject_id', $empB->id)->count());
    }

    public function test_manual_assignment_enables_publication(): void
    {
        $emp = $this->employee('M-300', 'Paul', 'Durand');
        $batch = $this->batch();
        $item = $this->service()->addSegment($batch, $this->segFile(), '1', "Salarié: inconnu"); // none → review
        $this->assertFalse($item->isPublishable());

        $this->service()->assign($item, $emp->id, $this->owner);
        $item->refresh();
        $this->assertTrue($item->isPublishable());

        $this->service()->publishBatch($batch, $this->owner);
        $this->assertSame(1, PublishedPayslip::where('document_subject_id', $emp->id)->count());
    }

    // ───────── Account-less, idempotence, rectificatif ─────────

    public function test_accountless_employee_payslip_published(): void
    {
        $emp = $this->employee('AL-1', 'Sans', 'Compte', null); // sans user
        $batch = $this->batch();
        $this->service()->addSegment($batch, $this->segFile(), '1', "Matricule: AL-1 Période: 2026-03");
        $this->service()->publishBatch($batch, $this->owner);

        $pub = PublishedPayslip::first();
        $this->assertSame($emp->id, $pub->document_subject_id);
        $this->assertSame('employee', $pub->document_subject_type);
    }

    public function test_identical_reimport_is_idempotent(): void
    {
        $emp = $this->employee('I-1');
        // Même contenu (même sha) + même période, importé deux fois → un seul bulletin publié
        $b1 = $this->batch();
        $seg = $this->segFile('same');
        $this->service()->addSegment($b1, $seg, '1', "Matricule: I-1 Période: 2026-04");
        $this->service()->publishBatch($b1, $this->owner);

        $b2 = $this->batch();
        $this->service()->addSegment($b2, $seg, '1', "Matricule: I-1 Période: 2026-04"); // même fichier → même sha
        $this->service()->publishBatch($b2, $this->owner);

        $this->assertSame(1, PublishedPayslip::where('company_id', $this->company->id)->whereNull('superseded_at')->count());
    }

    public function test_rectification_creates_new_version(): void
    {
        $emp = $this->employee('R-1');
        $b1 = $this->batch();
        $this->service()->addSegment($b1, $this->segFile('v1'), '1', "Matricule: R-1 Période: 2026-05");
        $this->service()->publishBatch($b1, $this->owner);

        // Rectificatif : même salarié + période, contenu DIFFÉRENT
        $b2 = $this->batch();
        $this->service()->addSegment($b2, $this->segFile('v2'), '1', "Matricule: R-1 Période: 2026-05");
        $this->service()->publishBatch($b2, $this->owner);

        $all = PublishedPayslip::where('document_subject_id', $emp->id)->where('period', '2026-05')->orderBy('version_no')->get();
        $this->assertCount(2, $all);                         // ancienne CONSERVÉE
        $this->assertNotNull($all[0]->superseded_at);        // v1 remplacée
        $this->assertNull($all[1]->superseded_at);           // v2 courante
        $this->assertSame(2, $all[1]->version_no);
    }

    public function test_partial_publish_not_silently_complete(): void
    {
        $this->employee('P-1');
        $batch = $this->batch();
        $this->service()->addSegment($batch, $this->segFile('ok'), '1', "Matricule: P-1 Période: 2026-06"); // certain
        $this->service()->addSegment($batch, $this->segFile('ko'), '2', "Salarié: inconnu total");          // review

        $this->service()->publishBatch($batch, $this->owner);
        $batch->refresh();
        // Un item reste en revue → le lot n'est PAS « published »
        $this->assertNotSame(PayslipImportBatch::STATUS_PUBLISHED, $batch->status);
        $this->assertSame(1, PublishedPayslip::count());
    }

    // ───────── Sécurité : validation + isolation ─────────

    public function test_security_rejects_non_pdf_zip(): void
    {
        $this->expectException(ValidationException::class);
        $fake = UploadedFile::fake()->createWithContent('evil.pdf', 'this is not a pdf');
        $this->service()->createBatch($this->company, $this->owner, [$fake]);
    }

    public function test_tenant_isolation_on_batch(): void
    {
        $batch = $this->batch();
        $other = Company::create(['name' => 'Other', 'slug' => 'other-m5', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($other);
        $otherRole = $this->setUpCompanyRbac($other);
        $outsider = User::factory()->create();
        $other->memberships()->create(['user_id' => $outsider->id, 'role' => 'owner', 'company_role_id' => $otherRole->id]);

        $this->actingAs($outsider)->withHeaders(['X-Company-Id' => $other->id])
            ->getJson("/api/company/payslip-imports/{$batch->id}")->assertNotFound();
    }

    public function test_self_service_only_sees_own_payslips(): void
    {
        $user = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $this->setUpCompanyRbac($this->company)->id]);
        // Un bulletin pour CE user
        PublishedPayslip::create(['company_id' => $this->company->id, 'document_subject_type' => 'user', 'document_subject_id' => $user->id, 'period' => '2026-01', 'file_path' => $this->segFile(), 'file_name' => 'b.pdf', 'file_sha256' => str_repeat('a', 64), 'version_no' => 1, 'created_at' => now()]);
        // Un bulletin pour un AUTRE
        PublishedPayslip::create(['company_id' => $this->company->id, 'document_subject_type' => 'user', 'document_subject_id' => 999, 'period' => '2026-01', 'file_path' => $this->segFile(), 'file_name' => 'c.pdf', 'file_sha256' => str_repeat('b', 64), 'version_no' => 1, 'created_at' => now()]);

        $list = $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/profile/payslips')->assertOk()->json('payslips');
        $this->assertCount(1, $list);                        // seulement le sien
    }
}
