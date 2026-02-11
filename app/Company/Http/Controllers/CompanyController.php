<?php

namespace App\Company\Http\Controllers;

use App\Company\Http\Requests\UpdateCompanyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CompanyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'company' => $request->attributes->get('company'),
        ]);
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $company->update($request->validated());

        return response()->json([
            'company' => $company->fresh(),
        ]);
    }
}
