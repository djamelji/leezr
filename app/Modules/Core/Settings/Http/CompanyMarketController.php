<?php

namespace App\Modules\Core\Settings\Http;

use App\Core\Markets\LegalStatus;
use App\Core\Markets\Market;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyMarketController
{
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $markets = Market::active()
            ->orderBy('sort_order')
            ->get(['id', 'key', 'name', 'currency', 'locale']);

        $legalStatuses = [];

        if ($company->market_key) {
            $legalStatuses = LegalStatus::where('market_key', $company->market_key)
                ->orderBy('sort_order')
                ->get(['id', 'key', 'name', 'description', 'vat_rate', 'is_default']);
        }

        return response()->json([
            'market_key' => $company->market_key,
            'legal_status_key' => $company->legal_status_key,
            'markets' => $markets,
            'legal_statuses' => $legalStatuses,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'market_key' => ['nullable', 'string', 'exists:markets,key'],
            'legal_status_key' => ['nullable', 'string'],
        ]);

        // Validate legal_status_key belongs to the selected market
        if (!empty($validated['legal_status_key']) && !empty($validated['market_key'])) {
            $exists = LegalStatus::where('market_key', $validated['market_key'])
                ->where('key', $validated['legal_status_key'])
                ->exists();

            if (!$exists) {
                return response()->json([
                    'message' => 'Legal status does not belong to the selected market.',
                ], 422);
            }
        }

        // Clear legal_status_key if market changed
        if (($validated['market_key'] ?? null) !== $company->market_key) {
            $validated['legal_status_key'] = null;
        }

        $company->update($validated);

        return response()->json([
            'message' => 'Company market updated.',
            'market_key' => $company->market_key,
            'legal_status_key' => $company->legal_status_key,
        ]);
    }
}
