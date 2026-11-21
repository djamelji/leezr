<?php

namespace App\Modules\Core\DataExport\Jobs;

use App\Core\DataExport\CompanyDataExport;
use App\Modules\Core\DataExport\CompanyDataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** ADR-845 — builds one company archive on the default queue (existing worker infrastructure). One attempt : a failure is reported, never retried blindly. */
class BuildCompanyDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $exportId) {}

    /**
     * ADR-850 (M10) — the job dies outside handle() (worker timeout, attempts exceeded after a queue retry) : the
     * export must not stay « running » for ever (it would block every new request of the company). Marked failed,
     * audited ; nothing else is touched.
     */
    public function failed(?\Throwable $e): void
    {
        $export = CompanyDataExport::find($this->exportId);
        if ($export && in_array($export->status, CompanyDataExport::IN_PROGRESS, true)) {
            $export->update(['status' => CompanyDataExport::FAILED, 'finished_at' => now(), 'error' => 'job_failed']);
            app(\App\Core\Audit\AuditLogger::class)->logCompany($export->company_id, \App\Core\Audit\AuditAction::COMPANY_DATA_EXPORT_FAILED, 'company_data_export', (string) $export->id, [
                'actorType' => 'system', 'actorId' => null, 'severity' => 'warning', 'metadata' => ['result' => 'failed', 'reason' => 'job_failed'],
            ]);
        }
    }

    public function handle(CompanyDataExportService $service): void
    {
        $export = CompanyDataExport::with('company.market')->find($this->exportId);
        if ($export && $export->status === CompanyDataExport::PENDING) {
            $service->build($export);
        }
    }
}
