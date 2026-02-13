<?php

namespace App\Company\Http\Controllers;

use App\Company\Fields\ReadModels\CompanyProfileReadModel;
use App\Company\Http\Requests\UpdateCompanyRequest;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CompanyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(CompanyProfileReadModel::get($company));
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $validated = $request->validated();

        $company->update(array_intersect_key($validated, array_flip(['name'])));

        if (isset($validated['dynamic_fields'])) {
            FieldWriteService::upsert(
                $company,
                $validated['dynamic_fields'],
                FieldDefinition::SCOPE_COMPANY,
                $company->id,
            );
        }

        return response()->json(CompanyProfileReadModel::get($company->fresh()));
    }
}
