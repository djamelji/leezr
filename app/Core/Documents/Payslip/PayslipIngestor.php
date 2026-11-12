<?php

namespace App\Core\Documents\Payslip;

use App\Core\Documents\DocumentProcessingPipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * ADR-762 / ADR-763 (DMS M5/M5.1) — ingestion d'un lot : extraction ZIP, lecture des PAGES source
 * (matricule/période par page, best-effort OCR), DÉCOUPAGE multipage par le segmenteur, puis
 * reconstitution + rapprochement via le service. Les frontières incertaines restent en revue.
 */
class PayslipIngestor
{
    public function __construct(
        private readonly PayslipImportService $import,
        private readonly PayslipBatchValidator $validator,
    ) {
    }

    public function ingest(PayslipImportBatch $batch): PayslipImportBatch
    {
        $batch->update(['status' => PayslipImportBatch::STATUS_PROCESSING]);
        $dir = "payslip-imports/{$batch->company_id}/{$batch->id}";
        $errors = [];

        // 1. Rassemble les PDF sources (PDF direct ou extraits d'une archive).
        $sources = [];
        foreach (Storage::disk('local')->files($dir) as $sourcePath) {
            if (! str_contains(basename($sourcePath), 'source-')) {
                continue;
            }
            $abs = Storage::disk('local')->path($sourcePath);
            try {
                $magic = $this->validator->magic($abs);
                if ($magic === 'pdf') {
                    $sources[] = $sourcePath;
                } elseif ($magic === 'zip') {
                    $sources = array_merge($sources, $this->extractZipPdfs($abs, $dir));
                }
            } catch (\Throwable $e) {
                $errors[] = ['file' => basename($sourcePath), 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        // 2. Lit les pages (identifiants par page).
        $pages = [];
        $pagesProcessed = 0;
        foreach ($sources as $relPath) {
            try {
                $abs = Storage::disk('local')->path($relPath);
                $pdf = new Fpdi();
                $count = $pdf->setSourceFile($abs);
                for ($p = 1; $p <= $count; $p++) {
                    $text = $this->extractPageText($abs, $p);
                    $ids = $this->import->extractIdentifiers($text);
                    $pages[] = [
                        'source_file_path' => $relPath, 'page_no' => $p,
                        'matricule' => $ids['matricule'], 'period' => $ids['period'],
                        'is_blank' => trim($text) === '', 'name' => $ids['name'],
                    ];
                    $pagesProcessed++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['file' => basename($relPath), 'error' => mb_substr($e->getMessage(), 0, 300)];
                Log::warning('[dms.payslip] ingest error', ['batch' => $batch->id, 'error' => $e->getMessage()]);
            }
        }

        // 3. Découpe + reconstitution + matching.
        if ($pages) {
            $this->import->buildSegments($batch, $pages);
        }

        PayslipQuotaUsage::bump($batch->company_id, filesImported: count($sources), pagesProcessed: $pagesProcessed);

        $batch->update([
            'status' => PayslipImportBatch::STATUS_REVIEW,
            'items_total' => $batch->items()->count(),
            'errors' => $errors ?: null,
            'processed_at' => now(),
        ]);

        return $batch->fresh();
    }

    /** Extrait les PDF d'une archive vers des fichiers sources (pour reconstitution ultérieure). */
    private function extractZipPdfs(string $absZip, string $dir): array
    {
        $out = [];
        $zip = new \ZipArchive();
        if ($zip->open($absZip) !== true) {
            return $out;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! str_ends_with(strtolower((string) $name), '.pdf')) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            $rel = $dir.'/source-zip-'.$i.'.pdf';
            Storage::disk('local')->put($rel, $content);
            if ($this->validator->magic(Storage::disk('local')->path($rel)) === 'pdf') {
                $out[] = $rel;
            }
        }
        $zip->close();

        return $out;
    }

    /** Texte d'une page (best-effort). Sans couche texte fiable → vide → la page reste en revue. */
    private function extractPageText(string $absPdf, int $page): string
    {
        try {
            $src = new Fpdi();
            $src->setSourceFile($absPdf);
            $tpl = $src->importPage($page);
            $size = $src->getTemplateSize($tpl);
            $src->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $src->useTemplate($tpl);
            $tmp = tempnam(sys_get_temp_dir(), 'paysl').'.pdf';
            file_put_contents($tmp, $src->Output('S'));

            $pipeline = app(DocumentProcessingPipeline::class);
            $text = method_exists($pipeline, 'extractTextFromPdf') ? (string) $pipeline->extractTextFromPdf($tmp) : '';
            @unlink($tmp);

            return $text;
        } catch (\Throwable) {
            return '';
        }
    }
}
