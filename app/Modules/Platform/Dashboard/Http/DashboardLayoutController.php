<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Modules\Dashboard\JobdomainDashboardDefault;
use App\Modules\Dashboard\LayoutValidator;
use App\Modules\Dashboard\PlatformUserDashboardLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardLayoutController extends Controller
{
    /**
     * GET /api/platform/dashboard/layout
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $request->user('platform')->id;
        $layout = PlatformUserDashboardLayout::where('user_id', $userId)->first();
        $tiles = $layout?->layout_json ?? self::defaultLayout();

        return response()->json([
            'layout' => DashboardWidgetRegistry::filterLayout($tiles),
        ]);
    }

    /**
     * PUT /api/platform/dashboard/layout
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'layout' => ['required', 'array'],
            'layout.*.key' => ['required', 'string'],
            'layout.*.x' => ['required', 'integer', 'min:0'],
            'layout.*.y' => ['required', 'integer', 'min:0'],
            'layout.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'layout.*.h' => ['required', 'integer', 'min:1', 'max:12'],
            'layout.*.scope' => ['required', 'in:global,company'],
            'layout.*.config' => ['nullable', 'array'],
        ]);

        $validation = LayoutValidator::validate($validated['layout']);

        if (!$validation['valid']) {
            return response()->json([
                'message' => 'Invalid layout.',
                'errors' => $validation['errors'],
            ], 422);
        }

        $userId = $request->user('platform')->id;

        PlatformUserDashboardLayout::updateOrCreate(
            ['user_id' => $userId],
            ['layout_json' => $validated['layout']],
        );

        return response()->json(['layout' => $validated['layout']]);
    }

    /**
     * GET /api/platform/dashboard/layout/presets
     */
    public function presets(): JsonResponse
    {
        $defaults = JobdomainDashboardDefault::with('jobdomain:id,key,label')->get();

        return response()->json([
            'presets' => $defaults->map(fn ($d) => [
                'jobdomain_key' => $d->jobdomain->key,
                'jobdomain_label' => $d->jobdomain->label,
                'layout' => $d->layout_json,
                'version' => $d->version,
            ])->values()->all(),
        ]);
    }

    private static function defaultLayout(): array
    {
        return [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'global', 'config' => ['period' => '30d']],
            ['key' => 'billing.refund_ratio', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => ['period' => '30d']],
            ['key' => 'billing.ar_outstanding', 'x' => 0, 'y' => 4, 'w' => 4, 'h' => 2, 'scope' => 'global', 'config' => []],
        ];
    }
}
