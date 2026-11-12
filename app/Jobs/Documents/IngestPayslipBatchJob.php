<?php

namespace App\Jobs\Documents;

use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-762 (DMS M5) — ingestion ASYNCHRONE d'un lot (découpage + extraction + matching). `uniqueId`
 * évite les ingestions concurrentes du même lot ; le service reste idempotent.
 */
class IngestPayslipBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $batchId)
    {
    }

    public function uniqueId(): string
    {
        return 'payslip-ingest-'.$this->batchId;
    }

    public function handle(PayslipIngestor $ingestor): void
    {
        $batch = PayslipImportBatch::find($this->batchId);
        if ($batch && $batch->status === PayslipImportBatch::STATUS_UPLOADED) {
            $ingestor->ingest($batch);
        }
    }
}
