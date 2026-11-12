<?php

namespace App\Jobs\Documents;

use App\Core\Documents\DocumentCampaign;
use App\Core\Documents\Services\DocumentCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-761 (DMS M4) — lancement ASYNCHRONE et reprenable d'une campagne. Le service est idempotent
 * et verrouillé → un retry ne crée pas de doublon. `uniqueId` évite les jobs concurrents pour la
 * même campagne.
 */
class LaunchDocumentCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $campaignId)
    {
    }

    public function uniqueId(): string
    {
        return 'campaign-launch-'.$this->campaignId;
    }

    public function handle(DocumentCampaignService $service): void
    {
        $campaign = DocumentCampaign::find($this->campaignId);
        if ($campaign && $campaign->status !== DocumentCampaign::STATUS_CANCELLED) {
            $service->launch($campaign);
        }
    }
}
