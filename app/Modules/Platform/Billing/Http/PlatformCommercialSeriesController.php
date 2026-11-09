<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\ReadModels\PlatformCommercialSeriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-747 (P2) — séries temporelles « Pilotage commercial » (MRR en niveau, période
 * sélectionnable, comparatif N-1). Gated `view_billing` (groupe de routes). Lecture seule.
 */
class PlatformCommercialSeriesController
{
    public function __invoke(Request $request, PlatformCommercialSeriesService $service): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:week,month,year'],
            'anchor' => ['nullable', 'date'],
            'compare' => ['nullable', 'boolean'],
        ]);

        return response()->json($service->series(
            $validated['period'] ?? 'month',
            $validated['anchor'] ?? null,
            filter_var($validated['compare'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ));
    }
}
