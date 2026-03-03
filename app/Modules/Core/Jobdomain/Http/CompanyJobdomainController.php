<?php

namespace App\Modules\Core\Jobdomain\Http;

use App\Core\Jobdomains\JobdomainCatalogReadModel;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyJobdomainController
{
    /**
     * Show the current company's jobdomain + resolved profile.
     */
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            JobdomainCatalogReadModel::forCompany($company)
            + ['available' => JobdomainCatalogReadModel::all()],
        );
    }

    /**
     * Assign a jobdomain to the current company.
     * Activates default modules via JobdomainGate.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'key' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');

        // ADR-134: Jobdomain is immutable once assigned
        // ADR-167a: jobdomain_key is always present — this guard always triggers
        if ($company->jobdomain_key) {
            return response()->json([
                'message' => 'Jobdomain cannot be changed once assigned. Contact support or create a new company.',
            ], 422);
        }

        $oldKey = $company->jobdomain_key;

        $jobdomain = JobdomainGate::assignToCompany($company, $request->input('key'));

        Log::info('jobdomain.changed', [
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'from' => $oldKey,
            'to' => $request->input('key'),
        ]);

        // ADR-125: publish after mutation
        app(RealtimePublisher::class)->publish(
            EventEnvelope::invalidation('jobdomain.changed', $company->id, ['from' => $oldKey, 'to' => $request->input('key')])
        );

        // ADR-130: audit log
        app(AuditLogger::class)->logCompany($company->id, AuditAction::JOBDOMAIN_CHANGED, 'company', (string) $company->id);

        return response()->json(
            ['message' => 'Jobdomain assigned.']
            + JobdomainCatalogReadModel::forCompany($company),
        );
    }
}
