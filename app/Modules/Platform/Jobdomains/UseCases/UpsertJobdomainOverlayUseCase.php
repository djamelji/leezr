<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainMarketOverlay;
use App\Core\Jobdomains\JobdomainPresetResolver;
use App\Core\Markets\Market;

class UpsertJobdomainOverlayUseCase
{
    /**
     * Create or update a market overlay for a jobdomain.
     *
     * Validation of JSON structure is done in the controller.
     * Mandatory guards are in the Resolver — not duplicated here.
     */
    public function execute(string $jobdomainKey, string $marketKey, array $validated): JobdomainMarketOverlay
    {
        Jobdomain::where('key', $jobdomainKey)->firstOrFail();
        Market::where('key', $marketKey)->firstOrFail();

        $overlay = JobdomainMarketOverlay::updateOrCreate(
            ['jobdomain_key' => $jobdomainKey, 'market_key' => $marketKey],
            $validated,
        );

        JobdomainPresetResolver::clearCache();

        return $overlay;
    }
}
