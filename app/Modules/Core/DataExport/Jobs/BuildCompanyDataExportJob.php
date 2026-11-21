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

    public function handle(CompanyDataExportService $service): void
    {
        $export = CompanyDataExport::with('company.market')->find($this->exportId);
        if ($export && $export->status === CompanyDataExport::PENDING) {
            $service->build($export);
        }
    }
}
