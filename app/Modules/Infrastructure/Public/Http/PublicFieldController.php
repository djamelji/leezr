<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Core\Fields\CompanyCreationFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * ADR-290: Public (no auth) endpoint for company-scope field definitions.
 * Returns field definitions for a (jobdomain, market) pair before registration.
 */
class PublicFieldController extends Controller
{
    /**
     * GET /api/public/fields?jobdomain={key}&market={key}
     */
    public function companyFields(Request $request): JsonResponse
    {
        $request->validate([
            'jobdomain' => ['required', 'string', 'exists:jobdomains,key'],
            'market' => ['sometimes', 'string', 'exists:markets,key'],
        ]);

        $fields = CompanyCreationFields::resolve(
            $request->query('jobdomain'),
            $request->query('market'),
            $request->header('X-Locale', 'en'),
        );

        return response()->json(['fields' => $fields]);
    }
}
