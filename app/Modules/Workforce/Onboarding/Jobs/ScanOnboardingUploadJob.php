<?php

namespace App\Modules\Workforce\Onboarding\Jobs;

use App\Core\Tenancy\InteractsWithTenant;
use App\Modules\Workforce\Onboarding\Models\OnboardingUpload;
use App\Modules\Workforce\Onboarding\OnboardingUploadScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-796.1 — analyses ONE quarantined deposit under its company (tenant job). Engine unavailable →
 * the deposit stays quarantined and the job is released with backoff ; the recovery command
 * (`onboarding:scan-uploads`, scheduled) picks up anything left behind. Payload = ids only.
 */
class ScanOnboardingUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, InteractsWithTenant, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(int $companyId, public int $uploadId)
    {
        $this->onTenant($companyId);
    }

    public function handle(): void
    {
        $this->runInTenant(function () {
            $status = app(OnboardingUploadScanService::class)->process($this->uploadId);
            if ($status === OnboardingUpload::QUARANTINED && $this->job && $this->job->getConnectionName() !== 'sync' && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        });
    }
}
