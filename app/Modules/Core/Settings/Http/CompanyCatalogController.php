<?php

namespace App\Modules\Core\Settings\Http;

use App\Core\Catalogs\CompanyCatalogReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-819 (Company 2.0) — unified catalogue of person fields and document types of the company (read only). */
class CompanyCatalogController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(CompanyCatalogReadModel::forCompany($request->attributes->get('company')));
    }
}
