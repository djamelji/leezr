<?php

namespace App\Modules\Core\Settings\Http;

use App\Core\Markets\LegalStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyLegalStructureController
{
    /**
     * Returns the company's current legal structure info.
     * No technical fields exposed (no vat_rate, no is_default, no market_key raw).
     */
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $marketName = null;
        $legalStatuses = [];

        if ($company->market_key) {
            $market = $company->market;

            if ($market) {
                $marketName = $market->name;

                $legalStatuses = LegalStatus::where('market_key', $company->market_key)
                    ->orderBy('sort_order')
                    ->get(['key', 'name', 'description']);
            }
        }

        return response()->json([
            'market_name' => $marketName,
            'legal_status_key' => $company->legal_status_key,
            'legal_statuses' => $legalStatuses,
        ]);
    }

    /**
     * Company can only update its legal_status_key (not market_key).
     * Market assignment is Platform-only.
     */
    public function updateLegalStatus(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        if (!$company->market_key) {
            return response()->json([
                'message' => 'No market assigned to this company. Contact your administrator.',
            ], 422);
        }

        $validated = $request->validate([
            'legal_status_key' => ['nullable', 'string', 'max:50'],
        ]);

        // Validate legal_status_key belongs to the company's market
        if (!empty($validated['legal_status_key'])) {
            $exists = LegalStatus::where('market_key', $company->market_key)
                ->where('key', $validated['legal_status_key'])
                ->exists();

            if (!$exists) {
                return response()->json([
                    'message' => 'This legal structure is not available for your market.',
                ], 422);
            }
        }

        $company->update([
            'legal_status_key' => $validated['legal_status_key'],
        ]);

        return response()->json([
            'message' => 'Legal structure updated.',
            'legal_status_key' => $company->legal_status_key,
        ]);
    }
}
