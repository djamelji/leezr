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
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-763 (DMS M5.1) — fiabilisation : découpage multipage (reconstitution complète), éditeur de
 * revue (regroupement/scission), révocation (retrait bloquant toutes versions).
 */
class DmsM51PayslipTest extends TestCase
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
        $this->company = Company::create(['name' => 'M51 Co', 'slug' => 'm51-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
    }

    private function service(): PayslipImportService
    {
        return app(PayslipImportService::class);
    }

    private function employee(string $matricule): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => null,
            'first_name' => 'E', 'last_name' => $matricule, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $matricule,
        ]);
    }

    /** Crée un PDF de N pages (contenu synthétique) et renvoie son chemin relatif stocké. */
    private function makePdf(int $pages): string
    {
        $pdf = new Fpdi();
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(40, 10, "Page {$i}");
        }
        $rel = "payslip-imports/{$this->company->id}/1/source-".uniqid().'.pdf';
        Storage::disk('local')->put($rel, $pdf->Output('S'));

        return $rel;
    }

    private function pageCount(string $relPath): int
    {
        return (new Fpdi())->setSourceFile(Storage::disk('local')->path($relPath));
    }

    private function batch(): PayslipImportBatch
    {
        return PayslipImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'processing']);
    }

    // ───────── P0 : découpage multipage + reconstitution ─────────

    public function test_multipage_payslip_reconstituted_in_full(): void
    {
        $this->employee('M-1');
        $src = $this->makePdf(3);
        $batch = $this->batch();

        // 3 pages, même matricule → UN bulletin de 3 pages
        $items = $this->service()->buildSegments($batch, [
            ['source_file_path' => $src, 'page_no' => 1, 'matricule' => 'M-1', 'period' => '2026-01', 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 2, 'matricule' => 'M-1', 'period' => null, 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 3, 'matricule' => 'M-1', 'period' => null, 'is_blank' => false, 'name' => null],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame([1, 2, 3], $items[0]->page_numbers);
        // Le PDF reconstitué contient bien 3 pages
        $this->assertSame(3, $this->pageCount($items[0]->file_path));
        $this->assertSame('certain', $items[0]->match_confidence);
    }

    public function test_consecutive_employees_split_into_segments(): void
    {
        $this->employee('A'); $this->employee('B');
        $src = $this->makePdf(4);
        $items = $this->service()->buildSegments($this->batch(), [
            ['source_file_path' => $src, 'page_no' => 1, 'matricule' => 'A', 'period' => null, 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 2, 'matricule' => 'A', 'period' => null, 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 3, 'matricule' => 'B', 'period' => null, 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 4, 'matricule' => 'B', 'period' => null, 'is_blank' => false, 'name' => null],
        ]);

        $this->assertCount(2, $items);
        $this->assertSame([1, 2], $items[0]->page_numbers);
        $this->assertSame([3, 4], $items[1]->page_numbers);
    }

    public function test_annex_attaches_to_preceding_segment(): void
    {
        $this->employee('AX');
        $src = $this->makePdf(2);
        $items = $this->service()->buildSegments($this->batch(), [
            ['source_file_path' => $src, 'page_no' => 1, 'matricule' => 'AX', 'period' => null, 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 2, 'matricule' => null, 'period' => null, 'is_blank' => false, 'name' => null], // annexe
        ]);

        $this->assertCount(1, $items);
        $this->assertSame([1, 2], $items[0]->page_numbers);  // l'annexe est rattachée
    }

    public function test_isolated_leading_page_is_quarantined(): void
    {
        $this->employee('Q1');
        $src = $this->makePdf(2);
        $items = $this->service()->buildSegments($this->batch(), [
            ['source_file_path' => $src, 'page_no' => 1, 'matricule' => null, 'period' => null, 'is_blank' => false, 'name' => null], // sans rattachement → quarantaine
            ['source_file_path' => $src, 'page_no' => 2, 'matricule' => 'Q1', 'period' => null, 'is_blank' => false, 'name' => null],
        ]);

        $this->assertCount(2, $items);
        $this->assertSame('review', $items[0]->state);       // quarantaine
        $this->assertFalse($items[0]->isPublishable());
        $this->assertSame('matched', $items[1]->state);
    }

    // ───────── Éditeur de revue ─────────

    public function test_split_and_regroup(): void
    {
        $this->employee('S1');
        $src = $this->makePdf(3);
        $items = $this->service()->buildSegments($this->batch(), [
            ['source_file_path' => $src, 'page_no' => 1, 'matricule' => 'S1', 'period' => null, 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 2, 'matricule' => 'S1', 'period' => null, 'is_blank' => false, 'name' => null],
            ['source_file_path' => $src, 'page_no' => 3, 'matricule' => 'S1', 'period' => null, 'is_blank' => false, 'name' => null],
        ]);
        $item = $items[0];
        $this->assertSame(3, $this->pageCount($item->file_path));

        // Scission : la page 3 part dans un nouveau segment → invalide la confirmation
        $new = $this->service()->split($item, [3]);
        $item->refresh();
        $this->assertFalse($item->confirmed);                // confirmation invalidée
        $this->assertSame('review', $item->state);
        $this->assertSame(2, $this->pageCount($item->file_path)); // reconstitué à 2 pages
        $this->assertSame(1, $this->pageCount($new->file_path));

        // Regroupement : on refusionne les deux
        $merged = $this->service()->regroup([$item->id, $new->id]);
        $this->assertSame(3, $this->pageCount($merged->file_path));
    }

    // ───────── Révocation : bloque toutes les versions ─────────

    public function test_revocation_blocks_download_including_versions(): void
    {
        $emp = $this->employee('RV-1');
        // v1 puis rectificatif v2
        foreach (['v1', 'v2'] as $v) {
            $b = $this->batch();
            $item = PayslipImportItem::create(['company_id' => $this->company->id, 'payslip_import_batch_id' => $b->id, 'file_path' => 'x-'.$v.'.pdf', 'file_sha256' => str_repeat($v === 'v1' ? 'a' : 'b', 32), 'extracted_matricule' => 'RV-1', 'extracted_period' => '2026-07', 'matched_employee_id' => $emp->id, 'match_confidence' => 'certain', 'state' => 'matched']);
            Storage::disk('local')->put('x-'.$v.'.pdf', "%PDF-1.4\n{$v}\n%%EOF");
            $this->service()->publishItem($item, $this->owner);
        }
        $this->assertSame(2, PublishedPayslip::where('document_subject_id', $emp->id)->count());
        $current = PublishedPayslip::where('document_subject_id', $emp->id)->whereNull('superseded_at')->first();
        $superseded = PublishedPayslip::where('document_subject_id', $emp->id)->whereNotNull('superseded_at')->first();

        // Révocation
        $this->service()->revoke($current, $this->owner, 'Erreur d\'attribution');

        // TOUTES les versions sont révoquées (aucun contournement par l'ancienne)
        $this->assertNotNull($current->fresh()->revoked_at);
        $this->assertNotNull($superseded->fresh()->revoked_at);

        // Téléchargement bloqué (410)
        $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->get("/api/company/people/employee-{$emp->id}/payslips/{$current->id}/download")->assertStatus(410);
    }
}
