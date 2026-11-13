<?php

namespace App\Jobs\Documents;

use App\Core\Tenancy\CompanyContext;
use App\Core\Tenancy\InteractsWithTenant;
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
    use Dispatchable, InteractsWithQueue, InteractsWithTenant, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * ADR-770 / ADR-733 — TENANT job : the company travels in the payload and the launch runs under
     * ITS scope (it used to rely on the no-op scope of a context-less worker, and threw under strict
     * tenancy). `$companyId` is optional only for jobs serialised before this change.
     */
    public function __construct(public int $campaignId, ?int $companyId = null)
    {
        if ($companyId !== null) {
            $this->onTenant($companyId);
        }
    }

    public function uniqueId(): string
    {
        return 'campaign-launch-'.$this->campaignId;
    }

    public function handle(DocumentCampaignService $service): void
    {
        // Legacy payload without tenant : read the owning company once, explicitly cross-tenant.
        $this->tenantCompanyId ??= CompanyContext::runGlobal(fn () => DocumentCampaign::whereKey($this->campaignId)->value('company_id'));
        if (! $this->tenantCompanyId) {
            return; // campaign deleted meanwhile
        }

        $this->runInTenant(function () use ($service) {
            $campaign = DocumentCampaign::find($this->campaignId); // tenant-scoped
            if ($campaign && $campaign->status !== DocumentCampaign::STATUS_CANCELLED) {
                $service->launch($campaign);
            }
        });
    }
}
