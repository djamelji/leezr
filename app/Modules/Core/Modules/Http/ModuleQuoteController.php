<?php

namespace App\Modules\Core\Modules\Http;

use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ModuleQuoteController
{
    /**
     * GET /api/modules/quote?keys[]=m1&keys[]=m2
     *
     * Returns a read-only pricing quote for the selected modules.
     * Requires auth + company context.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string'],
        ]);

        try {
            $quote = ModuleQuoteCalculator::quoteForCompany($company, $validated['keys']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($quote->toArray());
    }
}
