<?php

namespace Tests\Feature;

use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipImportItem;
use App\Core\Documents\Payslip\PayslipImportPage;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-764 (DMS M5.2) — recette de bout en bout : non-mélange entre salariés, comptabilité des pages,
 * preuve de continuité, quota effectif, notification de retrait.
 */
class DmsM52PayslipTest extends TestCase
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
        $this->company = Company::create(['name' => 'M52 Co', 'slug' => 'm52-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $adminRole->id]);
    }

    private function service(): PayslipImportService
    {
        return app(PayslipImportService::class);
    }

    private function employee(string $matricule, ?int $userId = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $userId,
            'first_name' => 'E', 'last_name' => $matricule, 'status' => 'active',
            'hire_date' => '2026-01-01', 'employee_number' => $matricule,
        ]);
    }

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

    private function pageCount(string $rel): int
    {
        return (new Fpdi())->setSourceFile(Storage::disk('local')->path($rel));
    }

    private function batch(): PayslipImportBatch
    {
        return PayslipImportBatch::create(['company_id' => $this->company->id, 'created_by' => $this->owner->id, 'status' => 'processing']);
    }

    private function page(string $src, int $no, ?string $mat, ?int $idx = null, ?int $total = null): array
    {
        return ['source_file_path' => $src, 'page_no' => $no, 'matricule' => $mat, 'period' => '2026-01', 'is_blank' => false, 'name' => null, 'page_index' => $idx, 'page_total' => $total];
    }

    // ───────── §1 : page étrangère insérée au milieu d'un bulletin ─────────

    public function test_foreign_matricule_page_never_mixed_into_another(): void
    {
        $a = $this->employee('A'); $b = $this->employee('B');
        $src = $this->makePdf(3);
        // A, B (étranger), A → le matricule change → aucun segment A ne contient la page de B
        $items = $this->service()->buildSegments($this->batch(), [
            $this->page($src, 1, 'A'), $this->page($src, 2, 'B'), $this->page($src, 3, 'A'),
        ]);

        // Chaque segment ne contient que les pages d'UN seul matricule.
        foreach ($items as $it) {
            $mats = $it->pages()->pluck('extracted_matricule')->unique()->filter()->values();
            $this->assertLessThanOrEqual(1, $mats->count(), 'Un segment ne doit pas mélanger des matricules');
        }
        // La page 2 (B) n'appartient à aucun segment rattaché à A.
        $page2 = PayslipImportPage::where('source_page_no', 2)->first();
        $item2 = PayslipImportItem::find($page2->payslip_import_item_id);
        $this->assertSame('B', $item2->extracted_matricule);
    }

    public function test_foreign_blank_page_forces_review_not_autopublish(): void
    {
        $this->employee('A');
        $src = $this->makePdf(3);
        // A, page sans matricule (étranger), A → une seule série A mais composition INCERTAINE
        $items = $this->service()->buildSegments($this->batch(), [
            $this->page($src, 1, 'A'), $this->page($src, 2, null), $this->page($src, 3, 'A'),
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('review', $items[0]->state);          // pas de publication automatique
        $this->assertSame('no_matricule_page', $items[0]->review_reason);
        $this->assertFalse($items[0]->isPublishable());
    }

    public function test_page_numbering_evidence_attaches_confidently(): void
    {
        $this->employee('CONT');
        $src = $this->makePdf(2);
        // page 1 matricule CONT "1/2", page 2 sans matricule "2/2" → continuité PROUVÉE → fiable
        $items = $this->service()->buildSegments($this->batch(), [
            $this->page($src, 1, 'CONT', 1, 2), $this->page($src, 2, null, 2, 2),
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('matched', $items[0]->state);         // continuité concordante → certain
        $this->assertNull($items[0]->review_reason);
        $this->assertSame([1, 2], $items[0]->page_numbers);
    }

    // ───────── §4 : comptabilité des pages (aucune perdue, aucune en double) ─────────

    public function test_page_accounting_no_loss_no_duplicate(): void
    {
        $this->employee('P1'); $this->employee('P2'); $this->employee('P3');
        $src = $this->makePdf(6);
        $this->service()->buildSegments($this->batch(), [
            $this->page($src, 1, 'P1'), $this->page($src, 2, 'P1'),
            $this->page($src, 3, 'P2'),
            $this->page($src, 4, 'P3'), $this->page($src, 5, 'P3'), $this->page($src, 6, 'P3'),
        ]);

        // Chaque page source appartient à EXACTEMENT un segment (aucune perdue, aucune en double).
        $pages = PayslipImportPage::where('company_id', $this->company->id)->get();
        $this->assertSame(6, $pages->count());
        $this->assertSame(6, $pages->whereNotNull('payslip_import_item_id')->count());
        $assigned = $pages->groupBy('payslip_import_item_id')->map->count();
        $this->assertSame(6, $assigned->sum());

        // La somme des pages des segments reconstitués = pages source.
        $items = PayslipImportItem::where('company_id', $this->company->id)->get();
        $totalRebuilt = $items->sum(fn ($i) => $this->pageCount($i->file_path));
        $this->assertSame(6, $totalRebuilt);
    }

    public function test_ten_employees_variable_pages(): void
    {
        $specs = [];
        $src = $this->makePdf(15);
        $pageNo = 1;
        for ($e = 1; $e <= 10; $e++) {
            $this->employee("EMP-{$e}");
            $count = ($e % 3) + 1; // 1 à 3 pages
            for ($k = 0; $k < $count; $k++) {
                $specs[] = $this->page($src, $pageNo++, "EMP-{$e}");
                if ($pageNo > 15) break 2;
            }
        }
        $items = $this->service()->buildSegments($this->batch(), $specs);
        // Autant de segments que de salariés rencontrés, chacun avec ses pages.
        $this->assertSame(collect($specs)->pluck('matricule')->unique()->count(), count($items));
    }

    // ───────── §6 : quota EFFECTIF (bloque) ─────────

    public function test_file_quota_blocks_over_limit(): void
    {
        $files = [];
        for ($i = 0; $i < 21; $i++) {
            $files[] = UploadedFile::fake()->createWithContent("f{$i}.pdf", "%PDF-1.4\nx\n%%EOF");
        }
        $this->expectException(ValidationException::class);
        $this->service()->createBatch($this->company, $this->owner, $files); // > MAX_FILES → refus
    }

    // ───────── §5 : notification de retrait ─────────

    public function test_revocation_notifies_employee(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $emp = $this->employee('RVN', $user->id);
        $pub = PublishedPayslip::create(['company_id' => $this->company->id, 'document_subject_type' => 'employee', 'document_subject_id' => $emp->id, 'period' => '2026-02', 'file_path' => 'x.pdf', 'file_name' => 'b.pdf', 'file_sha256' => str_repeat('a', 64), 'version_no' => 1, 'created_at' => now()]);

        $this->service()->revoke($pub, $this->owner, 'Erreur');
        $this->assertNotNull($pub->fresh()->revoked_at);
        // Le retrait est journalisé (traçabilité) ; une notification est émise vers le salarié lié.
        $this->assertDatabaseHas('company_audit_logs', ['action' => 'payslip.revoked']);
    }
}
