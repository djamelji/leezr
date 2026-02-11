<?php

namespace App\Company\Http\Controllers;

use App\Core\Jobdomains\JobdomainCatalogReadModel;
use App\Core\Jobdomains\JobdomainGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $jobdomain = JobdomainGate::assignToCompany($company, $request->input('key'));

        return response()->json(
            ['message' => 'Jobdomain assigned.']
            + JobdomainCatalogReadModel::forCompany($company),
        );
    }
}
