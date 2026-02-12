<?php

namespace App\Company\Http\Controllers;

use App\Core\Jobdomains\JobdomainCatalogReadModel;
use App\Core\Jobdomains\JobdomainGate;
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

        $oldKey = $company->jobdomain?->key;

        $jobdomain = JobdomainGate::assignToCompany($company, $request->input('key'));

        Log::info('jobdomain.changed', [
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'from' => $oldKey,
            'to' => $request->input('key'),
        ]);

        return response()->json(
            ['message' => 'Jobdomain assigned.']
            + JobdomainCatalogReadModel::forCompany($company),
        );
    }
}
