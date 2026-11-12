<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentSubject;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\ImageProcessor;
use App\Core\Documents\Payslip\PayslipBatchValidator;
use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipImportItem;
use App\Core\Documents\Payslip\PayslipImportPage;
use App\Core\Documents\Payslip\PayslipQuotaUsage;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-767 — REAL payslip import path: HTTP upload → IngestPayslipBatchJob → text extraction
 * (DocumentProcessingPipeline) → matching → review. Earlier suites injected the text straight into
 * `buildSegments()`/`addSegment()`, so the ingestor itself was never exercised: it called a method
 * that did not exist and every page came out blank.
 */
class Adr767PayslipPipelineTest extends TestCase
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
        [$this->company, $this->owner] = $this->makeCompany('adr767-a');
    }

    /** @return array{0: Company, 1: User} */
    private function makeCompany(string $slug): array
    {
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($company);
        $adminRole = $this->setUpCompanyRbac($company);
        $owner = User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);

        return [$company, $owner];
    }

    /** A member whose role holds EXACTLY the given permission keys. */
    private function memberWith(Company $company, array $permissionKeys, string $key): User
    {
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => $key, 'name' => $key, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        $user = User::factory()->create();
        $company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $user;
    }

    private function employee(Company $company, string $matricule, ?int $userId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $userId,
            'first_name' => 'Emp', 'last_name' => $matricule, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $matricule,
        ]);
    }

    /**
     * A REAL PDF with a text layer: one page per entry, each line written as selectable text.
     *
     * @param  list<list<string>>  $pages
     */
    private function textPdf(array $pages, string $name = 'payslips.pdf'): UploadedFile
    {
        $pdf = new Fpdi();
        foreach ($pages as $lines) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            foreach ($lines as $line) {
                $pdf->Cell(0, 10, $line, 0, 1);
            }
        }

        return UploadedFile::fake()->createWithContent($name, $pdf->Output('S'));
    }

    private function as(User $user, Company $company): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $company->id]);
    }

    // ───────── §6 : the real path extracts text and matches ─────────

    public function test_real_upload_extracts_text_and_matches_by_matricule(): void
    {
        $a = $this->employee($this->company, 'A100');
        $b = $this->employee($this->company, 'B200');

        $file = $this->textPdf([
            ['Bulletin de paie', 'Matricule: A100', 'Periode: 2026-01', 'Page 1/2'],
            ['Matricule: A100', 'Periode: 2026-01', 'Page 2/2'],
            ['Bulletin de paie', 'Matricule: B200', 'Periode: 2026-01', 'Page 1/1'],
        ]);

        // Real HTTP upload; QUEUE_CONNECTION=sync → IngestPayslipBatchJob really runs.
        $batchId = $this->as($this->owner, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$file]], ['Accept' => 'application/json'])
            ->assertCreated()->json('batch.id');

        $batch = PayslipImportBatch::withoutGlobalScopes()->findOrFail($batchId);
        $this->assertSame(PayslipImportBatch::STATUS_REVIEW, $batch->status);

        // Pages carry the text actually read from the PDF.
        $pages = PayslipImportPage::withoutGlobalScopes()->where('payslip_import_batch_id', $batchId)->orderBy('page_no')->get();
        $this->assertCount(3, $pages);
        $this->assertSame(['A100', 'A100', 'B200'], $pages->pluck('extracted_matricule')->all());
        $this->assertSame([1, 2, 1], $pages->pluck('page_index')->map(fn ($v) => (int) $v)->all());

        // Two payslips, each matched with certainty to the right employee.
        $items = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batchId)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame([PayslipImportItem::C_CERTAIN, PayslipImportItem::C_CERTAIN], $items->pluck('match_confidence')->all());
        $this->assertSame([$a->id, $b->id], $items->pluck('matched_employee_id')->all());
        $this->assertSame(['2026-01', '2026-01'], $items->pluck('extracted_period')->all());
        $this->assertSame([2, 1], $items->map(fn ($i) => count($i->page_numbers))->all());

        // Visible in the review centre, through the API the UI really calls.
        $review = $this->as($this->owner, $this->company)->getJson("/api/company/payslip-imports/{$batchId}/items")->assertOk()->json('items');
        $this->assertSame(['A100', 'B200'], array_column($review, 'extracted_matricule'));
        $this->assertSame(['certain', 'certain'], array_column($review, 'confidence'));
        $summary = $this->as($this->owner, $this->company)->getJson("/api/company/payslip-imports/{$batchId}")->assertOk()->json('counts');
        $this->assertSame(2, $summary['certain']);

        // Usage counters reflect the real run.
        $usage = PayslipQuotaUsage::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertSame(1, $usage->files_imported);
        $this->assertSame(3, $usage->pages_processed);
        $this->assertSame(0, $usage->ocr_pages, 'A text-layer PDF must not be billed as OCR.');
    }

    public function test_real_upload_then_publish_lands_in_the_right_employee_folder(): void
    {
        $a = $this->employee($this->company, 'A100');
        $b = $this->employee($this->company, 'B200');
        $file = $this->textPdf([
            ['Matricule: A100', 'Periode: 2026-02', 'Page 1/1'],
            ['Matricule: B200', 'Periode: 2026-02', 'Page 1/1'],
        ]);
        $batchId = $this->as($this->owner, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated()->json('batch.id');

        $this->as($this->owner, $this->company)->postJson("/api/company/payslip-imports/{$batchId}/publish")->assertOk();

        $published = PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->get();
        $this->assertCount(2, $published);
        foreach ([$a, $b] as $emp) {
            $own = $published->where('document_subject_type', DocumentSubject::TYPE_EMPLOYEE)->where('document_subject_id', $emp->id);
            $this->assertCount(1, $own, "Exactly one payslip for {$emp->employee_number}");
        }
    }

    public function test_ingestion_job_runs_in_its_tenant_outside_http_under_strict_tenancy(): void
    {
        $a = $this->employee($this->company, 'A100');
        [$other] = $this->makeCompany('adr767-w');
        $this->employee($other, 'A100'); // same matricule in another tenant

        $batch = app(\App\Core\Documents\Payslip\PayslipImportService::class)->createBatch(
            $this->company, $this->owner, [$this->textPdf([['Matricule: A100', 'Periode: 2026-04', 'Page 1/1']])],
        );

        // Worker conditions: no HTTP request, no tenant bound, strict tenancy (ADR-733).
        $this->assertFalse(\App\Core\Tenancy\CompanyContext::has());
        \App\Core\Tenancy\CompanyContext::$strict = true;
        try {
        // A job addressed to the WRONG tenant finds nothing (scoped lookup) and ingests nothing.
            (new \App\Jobs\Documents\IngestPayslipBatchJob($batch->id, $other->id))->handle(app(\App\Core\Documents\Payslip\PayslipIngestor::class));
            $this->assertSame(0, PayslipImportItem::withoutGlobalScopes()->count());
            (new \App\Jobs\Documents\IngestPayslipBatchJob($batch->id, $this->company->id))->handle(app(\App\Core\Documents\Payslip\PayslipIngestor::class));
        } finally {
            \App\Core\Tenancy\CompanyContext::$strict = false;
        }

        $item = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame($this->company->id, (int) $item->company_id);
        $this->assertSame($a->id, $item->matched_employee_id, 'Matched inside ITS tenant, never the homonymous matricule of another.');
        $this->assertSame(PayslipImportBatch::STATUS_REVIEW, PayslipImportBatch::withoutGlobalScopes()->find($batch->id)->status);
        $this->assertFalse(\App\Core\Tenancy\CompanyContext::has());
    }

    public function test_pdf_without_text_layer_stays_in_review_never_matched(): void
    {
        $this->employee($this->company, 'A100');

        // Blank pages: no text layer, nothing for OCR to read → safe default.
        $pdf = new Fpdi();
        $pdf->AddPage();
        $pdf->AddPage();
        $file = UploadedFile::fake()->createWithContent('scan.pdf', $pdf->Output('S'));

        $batchId = $this->as($this->owner, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated()->json('batch.id');

        $items = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batchId)->get();
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertNotSame(PayslipImportItem::S_MATCHED, $item->state);
            $this->assertNull($item->matched_employee_id);
        }
        // Nothing publishable.
        $stats = $this->as($this->owner, $this->company)->postJson("/api/company/payslip-imports/{$batchId}/publish")->assertOk()->json('stats');
        $this->assertSame(0, PublishedPayslip::withoutGlobalScopes()->count(), json_encode($stats));
    }

    public function test_scanned_image_pdf_is_read_by_ocr_fallback_and_counted(): void
    {
        $images = app(ImageProcessor::class);
        if (! $images->findTesseract() || ! $images->findMagick() || ! function_exists('imagettftext') && ! function_exists('imagestring')) {
            $this->markTestSkipped('OCR toolchain (tesseract + ImageMagick + GD) not available.');
        }
        $this->employee($this->company, 'A100');

        // A page that is ONLY an image (no text layer): text rendered into a bitmap.
        $small = imagecreatetruecolor(360, 60);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        $black = imagecolorallocate($small, 0, 0, 0);
        imagestring($small, 5, 8, 6, 'Matricule: A100', $black);
        imagestring($small, 5, 8, 32, 'Periode: 2026-03', $black);
        $big = imagecreatetruecolor(1800, 300);
        imagecopyresampled($big, $small, 0, 0, 0, 0, 1800, 300, 360, 60);
        $png = tempnam(sys_get_temp_dir(), 'adr767').'.png';
        imagepng($big, $png);

        $pdf = new Fpdi();
        $pdf->AddPage();
        $pdf->Image($png, 10, 20, 180);
        @unlink($png);
        $file = UploadedFile::fake()->createWithContent('scan.pdf', $pdf->Output('S'));

        $batchId = $this->as($this->owner, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated()->json('batch.id');

        // OCR really ran and read an identifier…
        $item = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batchId)->firstOrFail();
        $this->assertNotNull($item->extracted_matricule, 'OCR fallback must read the scanned page');
        $this->assertSame(1, PayslipQuotaUsage::withoutGlobalScopes()->where('company_id', $this->company->id)->value('ocr_pages'));

        // …but an OCR-read identifier is never a proof: review, never auto-matched, never published.
        $this->assertSame(PayslipImportItem::S_REVIEW, $item->state);
        $this->assertNull($item->matched_employee_id);
        $this->as($this->owner, $this->company)->postJson("/api/company/payslip-imports/{$batchId}/publish")->assertOk();
        $this->assertSame(0, PublishedPayslip::withoutGlobalScopes()->count());
    }

    public function test_ocr_read_matricule_matching_an_employee_is_demoted_to_review(): void
    {
        $a = $this->employee($this->company, 'A100');
        $batch = PayslipImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'processing']);
        $pdf = new Fpdi();
        $pdf->AddPage();
        $src = "payslip-imports/{$this->company->id}/{$batch->id}/source-0.bin";
        Storage::disk('local')->put($src, $pdf->Output('S'));

        $page = ['source_file_path' => $src, 'page_no' => 1, 'matricule' => 'A100', 'period' => '2026-01', 'is_blank' => false, 'name' => null];

        [$ocr] = app(\App\Core\Documents\Payslip\PayslipImportService::class)->buildSegments($batch, [$page + ['text_source' => 'ocr']]);
        $this->assertSame(PayslipImportItem::S_REVIEW, $ocr->state);
        $this->assertSame('ocr_unverified', $ocr->review_reason);
        $this->assertNull($ocr->matched_employee_id);
        $this->assertSame(PayslipImportItem::C_CERTAIN, $ocr->match_confidence);
        $this->assertContains($a->id, $ocr->match_candidates ?? [], 'The reviewer keeps the candidate to confirm in one click.');
        $this->assertFalse($ocr->isPublishable());
    }

    // ───────── tenant isolation on the real path ─────────

    public function test_matricule_of_another_company_never_matches_and_batch_is_not_visible(): void
    {
        [$other, $otherOwner] = $this->makeCompany('adr767-b');
        $foreign = $this->employee($other, 'Z900'); // exists ONLY in the other company

        $file = $this->textPdf([['Matricule: Z900', 'Periode: 2026-01', 'Page 1/1']]);
        $batchId = $this->as($this->owner, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated()->json('batch.id');

        $item = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $batchId)->firstOrFail();
        $this->assertSame('Z900', $item->extracted_matricule, 'Text must really be extracted');
        $this->assertNull($item->matched_employee_id);
        $this->assertNotSame($foreign->id, $item->matched_employee_id);
        $this->assertSame(PayslipImportItem::C_NONE, $item->match_confidence);

        // The other tenant sees neither the batch nor its items.
        $this->as($otherOwner, $other)->getJson("/api/company/payslip-imports/{$batchId}")->assertNotFound();
        $this->as($otherOwner, $other)->getJson("/api/company/payslip-imports/{$batchId}/items")->assertNotFound();
        $this->assertSame([], $this->as($otherOwner, $other)->getJson('/api/company/payslip-imports')->assertOk()->json('batches'));
    }

    // ───────── P3-b : an importer follows ITS batches, without the review data ─────────

    public function test_import_only_profile_tracks_own_batches_but_not_review_data(): void
    {
        $this->employee($this->company, 'A100');
        $importer = $this->memberWith($this->company, ['payslips.import'], 'importer');
        $otherImporter = $this->memberWith($this->company, ['payslips.import'], 'importer2');
        $reviewer = $this->memberWith($this->company, ['payslips.review'], 'reviewer');

        $mine = $this->as($importer, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$this->textPdf([['Matricule: A100', 'Periode: 2026-01', 'Page 1/1']])]], ['Accept' => 'application/json'])
            ->assertCreated()->json('batch.id');
        $theirs = $this->as($otherImporter, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$this->textPdf([['Matricule: A100', 'Periode: 2026-02', 'Page 1/1']])]], ['Accept' => 'application/json'])
            ->assertCreated()->json('batch.id');

        // The importer sees the status of ITS batch only.
        $list = $this->as($importer, $this->company)->getJson('/api/company/payslip-imports')->assertOk()->json('batches');
        $this->assertSame([$mine], array_column($list, 'id'));
        $this->assertSame(PayslipImportBatch::STATUS_REVIEW, $list[0]['status']);

        // …and never the review data (matches, candidates, previews) nor the mutations.
        $this->as($importer, $this->company)->getJson("/api/company/payslip-imports/{$mine}")->assertForbidden();
        $this->as($importer, $this->company)->getJson("/api/company/payslip-imports/{$mine}/items")->assertForbidden();
        $itemId = PayslipImportItem::withoutGlobalScopes()->where('payslip_import_batch_id', $mine)->value('id');
        $this->as($importer, $this->company)->get("/api/company/payslip-imports/{$mine}/items/{$itemId}/preview")->assertForbidden();
        $this->as($importer, $this->company)->putJson("/api/company/payslip-imports/{$mine}/items/{$itemId}/exclude")->assertForbidden();
        $this->as($importer, $this->company)->postJson("/api/company/payslip-imports/{$mine}/publish")->assertForbidden();

        // A reviewer sees every batch of the company, but cannot upload.
        $all = $this->as($reviewer, $this->company)->getJson('/api/company/payslip-imports')->assertOk()->json('batches');
        $this->assertEqualsCanonicalizing([$mine, $theirs], array_column($all, 'id'));
        $this->as($reviewer, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$this->textPdf([['x']])]], ['Accept' => 'application/json'])->assertForbidden();

        // No payslip permission at all → no tracking.
        $nobody = $this->memberWith($this->company, ['documents.view'], 'docviewer');
        $this->as($nobody, $this->company)->getJson('/api/company/payslip-imports')->assertForbidden();
    }

    // ───────── P3-a : one single source for the per-batch file limit ─────────

    public function test_file_limit_has_a_single_source_and_blocks(): void
    {
        $this->assertSame(PayslipQuotaUsage::MAX_FILES_PER_BATCH, PayslipBatchValidator::MAX_FILES);

        $files = [];
        for ($i = 0; $i <= PayslipQuotaUsage::MAX_FILES_PER_BATCH; $i++) {
            $files[] = $this->textPdf([['x']], "f{$i}.pdf");
        }
        $this->as($this->owner, $this->company)
            ->post('/api/company/payslip-imports', ['files' => $files], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(0, PayslipImportBatch::withoutGlobalScopes()->count());
    }

    // ───────── P3-e : server guarantees of the person payslips endpoint ─────────

    public function test_person_payslips_endpoint_requires_payroll_access_and_is_tenant_scoped(): void
    {
        $emp = $this->employee($this->company, 'A100');
        $file = $this->textPdf([['Matricule: A100', 'Periode: 2026-01', 'Page 1/1']]);
        $batchId = $this->as($this->owner, $this->company)
            ->post('/api/company/payslip-imports', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated()->json('batch.id');
        $this->as($this->owner, $this->company)->postJson("/api/company/payslip-imports/{$batchId}/publish")->assertOk();
        $payslipId = PublishedPayslip::withoutGlobalScopes()->where('company_id', $this->company->id)->value('id');
        $this->assertNotNull($payslipId);

        $url = "/api/company/people/employee-{$emp->id}/payslips";

        // Generic document rights never open payslips (list nor file).
        $docManager = $this->memberWith($this->company, ['documents.view', 'documents.view_confidential'], 'docs');
        $this->as($docManager, $this->company)->getJson($url)->assertForbidden();
        $this->as($docManager, $this->company)->get("{$url}/{$payslipId}/download")->assertForbidden();

        // Payroll right → list + download.
        $payroll = $this->memberWith($this->company, ['documents.view', 'payslips.download'], 'payroll');
        $list = $this->as($payroll, $this->company)->getJson($url)->assertOk()->json('payslips');
        $this->assertCount(1, $list);
        $this->assertTrue($list[0]['can_download']);
        $this->as($payroll, $this->company)->get("{$url}/{$payslipId}/download")->assertOk();

        // Another tenant: the personRef does not resolve there, the payslip is never reachable.
        [$other, $otherOwner] = $this->makeCompany('adr767-c');
        $res = $this->as($otherOwner, $other)->getJson($url);
        $this->assertContains($res->status(), [403, 404, 422]);
        $this->assertNull($res->json('payslips.0'));
        $dl = $this->as($otherOwner, $other)->get("{$url}/{$payslipId}/download");
        $this->assertContains($dl->status(), [403, 404, 422]);
    }
}
