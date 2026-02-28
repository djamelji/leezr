<?php

namespace App\Modules\Platform\Billing\Http;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Dashboard\BillingWidgetRegistry;
use App\Modules\Platform\Billing\Http\Requests\WidgetResolveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PlatformBillingWidgetsController extends Controller
{
    /**
     * List available billing widgets.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $widgets = collect(BillingWidgetRegistry::all())->map(fn ($w) => [
            'key' => $w->key(),
            'label_key' => $w->labelKey(),
            'default_period' => $w->defaultPeriod(),
        ])->values()->all();

        return response()->json(['widgets' => $widgets]);
    }

    /**
     * Resolve a single widget's data.
     */
    public function show(WidgetResolveRequest $request, string $key): JsonResponse
    {
        $widget = BillingWidgetRegistry::find($key);

        if (!$widget) {
            return response()->json(['message' => 'Widget not found.'], 404);
        }

        $companyId = (int) $request->validated('company_id');
        $period = $request->validated('period') ?? $widget->defaultPeriod();

        try {
            $data = $widget->resolve($companyId, $period);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json(['data' => $data]);
    }
}
