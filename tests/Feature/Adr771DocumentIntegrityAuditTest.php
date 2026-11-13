<?php

namespace Tests\Feature;

use App\Console\Commands\DocumentIntegrityAuditCommand as Audit;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Documents\DocumentVersion;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Payslip\PublishedPayslip;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ADR-771 — `documents:integrity-audit` detects the traces of the historical destructive behaviours
 * with REAL files, classifies them (proven corruption vs suspect vs missing vs tenant incoherence
 * vs orphan) and never writes anything : database and disk are byte-identical after the run.
 */
class Adr771DocumentIntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;
    private int $typeId;
    private int $otherTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        $this->a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique']);
        [$this->typeId, $this->otherTypeId] = DocumentType::orderBy('id')->limit(2)->pluck('id')->all();
    }

    private function write(string $path, string $content): string
    {
        Storage::disk('local')->put($path, $content);

        return hash('sha256', $content);
    }

    private function member(Company $c, string $path, ?string $sha, array $over = []): MemberDocument
    {
        return MemberDocument::withoutGlobalScopes()->create(array_merge([
            'company_id' => $c->id, 'document_type_id' => $this->typeId, 'file_path' => $path, 'file_name' => basename($path),
            'file_size_bytes' => Storage::disk('local')->exists($path) ? Storage::disk('local')->size($path) : 10, 'mime_type' => 'application/pdf',
            'file_sha256' => $sha, 'document_subject_type' => 'employee', 'document_subject_id' => random_int(1000, 9999),
        ], $over));
    }

    /** @return array{0:int,1:array} exit code + JSON report */
    private function audit(array $options = []): array
    {
        $code = Artisan::call('documents:integrity-audit', $options + ['--format' => 'json']);

        return [$code, json_decode(trim(Artisan::output()), true)];
    }

    private function fingerprint(): array
    {
        $db = [];
        foreach (['member_documents', 'document_versions', 'company_documents', 'published_payslips', 'generated_documents', 'workforce_signature_requests', 'company_audit_logs'] as $t) {
            $db[$t] = hash('sha256', json_encode(DB::table($t)->orderBy('id')->get()));
        }
        $files = [];
        foreach (Storage::disk('local')->allFiles() as $f) {
            $files[$f] = hash('sha256', Storage::disk('local')->get($f));
        }

        return [$db, $files];
    }

    private function by(array $report, string $class): array
    {
        return array_values(array_filter($report['findings'], fn ($f) => $f['class'] === $class));
    }

    public function test_a_healthy_store_reports_nothing_and_exits_zero(): void
    {
        $sha = $this->write("documents/{$this->a->id}/members/AbC123.pdf", '%PDF healthy A');
        $this->member($this->a, "documents/{$this->a->id}/members/AbC123.pdf", $sha);
        $shaC = $this->write("documents/{$this->a->id}/company/Zz9.pdf", '%PDF company');
        CompanyDocument::withoutGlobalScopes()->create(['company_id' => $this->a->id, 'document_type_id' => $this->typeId, 'file_path' => "documents/{$this->a->id}/company/Zz9.pdf",
            'file_name' => 'k.pdf', 'file_size_bytes' => strlen('%PDF company'), 'mime_type' => 'application/pdf', 'file_sha256' => $shaC,
            'uploaded_by' => \App\Core\Models\User::factory()->create()->id]);

        [$code, $report] = $this->audit(['--orphans' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([Audit::CORRUPT => 0, Audit::SUSPECT => 0, Audit::MISSING => 0, Audit::TENANT => 0, Audit::ORPHAN => 0], $report['totals']);
        $this->assertTrue($report['read_only']);
    }

    public function test_each_historical_trace_is_classified_and_nothing_is_ever_written(): void
    {
        $a = $this->a->id;

        // 1. Destructive replacement : the archived version and the current document point to the SAME
        //    deterministic file, but declare two different fingerprints → PROVEN corruption.
        $newSha = $this->write("documents/{$a}/members/7/id_card.pdf", '%PDF new bytes');
        $current = $this->member($this->a, "documents/{$a}/members/7/id_card.pdf", $newSha);
        $version = DocumentVersion::withoutGlobalScopes()->create(['company_id' => $a, 'documentable_type' => MemberDocument::class, 'documentable_id' => $current->id,
            'member_document_id' => $current->id, 'subject_type' => 'employee', 'subject_id' => 1, 'version_no' => 1, 'file_path' => "documents/{$a}/members/7/id_card.pdf",
            'file_name' => 'id.pdf', 'file_size_bytes' => 14, 'mime_type' => 'application/pdf', 'file_sha256' => hash('sha256', '%PDF old bytes'), 'source' => 'upload']);

        // 2. Shared path between two account-less employees : same recorded fingerprint (the last
        //    writer's), two subjects → SUSPECT (not provable : who was overwritten is unknown).
        $shared = $this->write("documents/{$a}/subject/passport.pdf", '%PDF shared');
        $s1 = $this->member($this->a, "documents/{$a}/subject/passport.pdf", $shared, ['document_type_id' => $this->otherTypeId, 'document_subject_id' => 11]);
        $s2 = $this->member($this->a, "documents/{$a}/subject/passport.pdf", $shared, ['document_type_id' => $this->otherTypeId, 'document_subject_id' => 12]);

        // 3. Recorded fingerprint ≠ stored bytes → PROVEN corruption.
        $this->write("documents/{$a}/members/Qq1.pdf", '%PDF tampered');
        $tampered = $this->member($this->a, "documents/{$a}/members/Qq1.pdf", hash('sha256', '%PDF original'), ['document_subject_id' => 13]);

        // 4. Missing file.
        $missing = PublishedPayslip::withoutGlobalScopes()->create(['company_id' => $a, 'document_subject_type' => 'user', 'document_subject_id' => 5, 'period' => '2026-01',
            'file_path' => "payslips/{$a}/gone.pdf", 'file_name' => 'b.pdf', 'file_sha256' => str_repeat('a', 64), 'version_no' => 1, 'created_at' => now()]);

        // 5. Stored under ANOTHER company's folder.
        $foreignSha = $this->write("documents/{$this->b->id}/members/Ff1.pdf", '%PDF misplaced');
        $misplaced = $this->member($this->a, "documents/{$this->b->id}/members/Ff1.pdf", $foreignSha, ['document_subject_id' => 14]);

        // 6. No fingerprint → not verifiable. 7. An unreferenced file.
        $this->write("documents/{$a}/members/NoSha.pdf", '%PDF nosha');
        $noSha = $this->member($this->a, "documents/{$a}/members/NoSha.pdf", null, ['document_subject_id' => 15]);
        $this->write("documents/{$a}/members/Orphan.pdf", '%PDF orphan');

        // Company B : one healthy document, must stay out of A's audit and untouched.
        $shaB = $this->write("documents/{$this->b->id}/members/Bb1.pdf", '%PDF healthy B');
        $this->member($this->b, "documents/{$this->b->id}/members/Bb1.pdf", $shaB);

        $before = $this->fingerprint();
        [$code, $report] = $this->audit(['--orphans' => true]);
        $after = $this->fingerprint();

        // ── Read-only : database (audit log included) and every file are byte-identical ──
        $this->assertSame($before, $after, 'the audit wrote something');
        $this->assertSame(2, $code, 'a proven corruption makes the command exit non-zero');

        $ids = fn (string $class, string $table) => collect($this->by($report, $class))->where('table', $table)->pluck('id')->sort()->values()->all();

        // Proven : the overwritten pair (both references) and the tampered file — nothing else.
        $this->assertSame([$current->id, $tampered->id], $ids(Audit::CORRUPT, 'member_documents'));
        $this->assertSame([$version->id], $ids(Audit::CORRUPT, 'document_versions'));
        $this->assertSame(3, $report['totals'][Audit::CORRUPT]);

        // Suspect, NOT proven : the shared path (2 refs + their legacy path), the legacy path of the
        // overwritten pair, the missing fingerprint.
        $suspects = collect($this->by($report, Audit::SUSPECT));
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $suspects->filter(fn ($f) => str_contains($f['reason'], 'partagé'))->pluck('id')->all());
        $this->assertContains($noSha->id, $suspects->filter(fn ($f) => str_contains($f['reason'], 'empreinte absente'))->pluck('id')->all());
        $this->assertNotContains($tampered->id, $suspects->pluck('id')->all(), 'a proven corruption is never downgraded to suspect');

        $this->assertSame([$missing->id], $ids(Audit::MISSING, 'published_payslips'));
        $this->assertSame([$misplaced->id], $ids(Audit::TENANT, 'member_documents'));
        $this->assertSame(["documents/{$a}/members/Orphan.pdf"], array_column($this->by($report, Audit::ORPHAN), 'path'));

        // Company B is healthy and reported as such ; nothing of a document's CONTENT is in the report.
        $this->assertArrayNotHasKey((string) $this->b->id, $report['by_company']);
        $this->assertStringNotContainsString('tampered', json_encode($report));

        // --company bounds the audit strictly.
        [$codeB, $reportB] = $this->audit(['--company' => $this->b->id]);
        $this->assertSame([0, 0], [$codeB, array_sum($reportB['totals'])]);
        [, $reportA] = $this->audit(['--company' => $a, '--limit' => 1]);
        $this->assertSame(3, $reportA['totals'][Audit::CORRUPT], 'counters stay exact when the listing is truncated');
        $this->assertCount(1, $this->by($reportA, Audit::CORRUPT));
        $this->assertTrue($reportA['truncated']);
        $this->assertSame($before, $this->fingerprint());
    }
}
