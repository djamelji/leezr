<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Core\Jobdomains\JobdomainMarketOverlay;
use App\Core\Jobdomains\JobdomainPresetResolver;

class DeleteJobdomainOverlayUseCase
{
    public function execute(string $jobdomainKey, string $marketKey): void
    {
        $overlay = JobdomainMarketOverlay::where('jobdomain_key', $jobdomainKey)
            ->where('market_key', $marketKey)
            ->firstOrFail();

        $overlay->delete();

        JobdomainPresetResolver::clearCache();
    }
}
