<?php

namespace App\Jobs\Documents;

use App\Core\Documents\Payslip\PayslipImportBatch;
use App\Core\Documents\Payslip\PayslipIngestor;
use App\Core\Tenancy\InteractsWithTenant;
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
    use Dispatchable, InteractsWithQueue, InteractsWithTenant, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * ADR-767 / ADR-733 — tenant job: the company travels in the payload (`InteractsWithTenant`) and the
     * whole ingestion runs under ITS scope. No scope bypass: a batch of another tenant is simply not found.
     */
    public function __construct(public int $batchId, int $companyId)
    {
        $this->onTenant($companyId);
    }

    public function uniqueId(): string
    {
        return 'payslip-ingest-'.$this->batchId;
    }

    public function handle(PayslipIngestor $ingestor): void
    {
        $this->runInTenant(function () use ($ingestor) {
            $batch = PayslipImportBatch::find($this->batchId); // tenant-scoped
            if ($batch && $batch->status === PayslipImportBatch::STATUS_UPLOADED) {
                $ingestor->ingest($batch);
            }
        });
    }
}
