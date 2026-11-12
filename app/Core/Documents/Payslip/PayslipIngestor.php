<?php

namespace App\Core\Documents\Payslip;

use App\Core\Documents\DocumentProcessingPipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * ADR-762 (DMS M5) — ingestion d'un lot : extraction des fichiers (ZIP), DÉCOUPAGE d'un PDF multipage
 * en segments (une page = un bulletin par défaut ; une frontière ambiguë reste en revue), extraction
 * de texte (best-effort OCR, réutilise DocumentProcessingPipeline), puis rapprochement sûr via le
 * service. Aucun contenu n'est exécuté ; stockage privé.
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
        $segIndex = 0;
        $errors = [];

        foreach (Storage::disk('local')->files($dir) as $sourcePath) {
            if (! str_contains(basename($sourcePath), 'source-')) {
                continue;
            }
            $abs = Storage::disk('local')->path($sourcePath);
            try {
                $magic = $this->validator->magic($abs);
                if ($magic === 'pdf') {
                    $segIndex = $this->splitPdf($batch, $abs, $dir, $segIndex);
                } elseif ($magic === 'zip') {
                    $segIndex = $this->ingestZip($batch, $abs, $dir, $segIndex);
                }
            } catch (\Throwable $e) {
                $errors[] = ['file' => basename($sourcePath), 'error' => mb_substr($e->getMessage(), 0, 300)];
                Log::warning('[dms.payslip] ingest error', ['batch' => $batch->id, 'error' => $e->getMessage()]);
            }
        }

        $batch->update([
            'status' => PayslipImportBatch::STATUS_REVIEW,
            'items_total' => $batch->items()->count(),
            'errors' => $errors ?: null,
            'processed_at' => now(),
        ]);

        return $batch->fresh();
    }

    /** Découpe un PDF en segments d'une page, stocke chacun, crée un item (matching). */
    private function splitPdf(PayslipImportBatch $batch, string $absPath, string $dir, int $segIndex): int
    {
        $src = new Fpdi();
        $pageCount = $src->setSourceFile($absPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $out = new Fpdi();
            $tplId = $out->importPage($page);
            $size = $out->getTemplateSize($tplId);
            $out->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $out->useTemplate($tplId);

            $relPath = $dir.'/segment-'.(++$segIndex).'.pdf';
            Storage::disk('local')->put($relPath, $out->Output('S'));

            $text = $this->extractText(Storage::disk('local')->path($relPath));
            $this->import->addSegment($batch, $relPath, (string) $page, $text);
        }

        return $segIndex;
    }

    private function ingestZip(PayslipImportBatch $batch, string $absPath, string $dir, int $segIndex): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($absPath) !== true) {
            return $segIndex;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! str_ends_with(strtolower((string) $name), '.pdf')) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'paysl');
            file_put_contents($tmp, $zip->getFromIndex($i));
            if ($this->validator->magic($tmp) === 'pdf') {
                $segIndex = $this->splitPdf($batch, $tmp, $dir, $segIndex);
            }
            @unlink($tmp);
        }
        $zip->close();

        return $segIndex;
    }

    /** Texte best-effort (OCR via le pipeline existant). Échec → chaîne vide → item en revue. */
    private function extractText(string $pdfPath): string
    {
        try {
            $pipeline = app(DocumentProcessingPipeline::class);
            if (method_exists($pipeline, 'extractTextFromPdf')) {
                return (string) $pipeline->extractTextFromPdf($pdfPath);
            }
        } catch (\Throwable) {
            // Aucun texte exploitable → revue manuelle (défaut SÛR).
        }

        return '';
    }
}
