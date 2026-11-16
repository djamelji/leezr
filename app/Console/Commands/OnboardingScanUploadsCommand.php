<?php

namespace App\Console\Commands;

use App\Core\Documents\Scanning\FileScannerManager;
use App\Modules\Workforce\Onboarding\Models\OnboardingUpload;
use App\Modules\Workforce\Onboarding\OnboardingUploadScanService;
use Illuminate\Console\Command;

/**
 * ADR-796.1 — recovery of quarantined deposits (worker down, engine unavailable, crash) : each one is
 * analysed again through the same idempotent service. `--simulate` uses the SIMULATED adapter (DEV
 * acceptance only, refused in production) — it is not an analysis and is recorded as `simulated`.
 */
class OnboardingScanUploadsCommand extends Command
{
    protected $signature = 'onboarding:scan-uploads {--simulate : DEV only — simulated adapter, not an analysis} {--upload=* : restrict to these deposit ids}';

    protected $description = 'Analyse quarantined onboarding deposits (recovery after a technical failure)';

    public function handle(): int
    {
        if ($this->option('simulate') && app()->isProduction()) {
            $this->error('--simulate is refused in production.');

            return self::FAILURE;
        }
        $service = new OnboardingUploadScanService($this->option('simulate') ? FileScannerManager::driver('simulated') : null);
        $ids = OnboardingUpload::where('status', OnboardingUpload::QUARANTINED)
            ->when($this->option('upload'), fn ($q, $ids) => $q->whereIn('id', array_map('intval', $ids)))->orderBy('id')->pluck('id');
        $out = [];
        foreach ($ids as $id) {
            $status = $service->process($id);
            $out[$status] = ($out[$status] ?? 0) + 1;
        }
        $this->line('Processed: '.$ids->count().' '.json_encode($out));

        return self::SUCCESS;
    }
}
