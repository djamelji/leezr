<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DashboardWidgetController extends Controller
{
    /**
     * GET /api/platform/dashboard/widgets/catalog
     *
     * Returns widget catalog filtered by the user's permissions.
     */
    public function catalog(Request $request): JsonResponse
    {
        $user = $request->user('platform');
        $widgets = DashboardWidgetRegistry::catalogForUser($user);

        return response()->json([
            'widgets' => collect($widgets)->map(fn ($w) => [
                'key' => $w->key(),
                'module' => $w->module(),
                'label_key' => $w->labelKey(),
                'description_key' => $w->descriptionKey(),
                'scope' => $w->scope(),
                'default_config' => $w->defaultConfig(),
                'layout' => $w->layout(),
                'category' => $w->category(),
                'tags' => $w->tags(),
                'component' => $w->component(),
                'audience' => $w->audience(),
            ])->values()->all(),
        ]);
    }

    /**
     * POST /api/platform/dashboard/widgets/data
     *
     * Batch resolve multiple widgets.
     */
    public function batchResolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'widgets' => ['required', 'array', 'min:1', 'max:20'],
            'widgets.*.key' => ['required', 'string'],
            'widgets.*.scope' => ['required', 'in:global,company'],
            'widgets.*.company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'widgets.*.period' => ['nullable', 'in:7d,30d,90d'],
        ]);

        $user = $request->user('platform');
        $results = [];

        foreach ($validated['widgets'] as $req) {
            $widget = DashboardWidgetRegistry::find($req['key']);

            if (!$widget) {
                $results[] = ['key' => $req['key'], 'error' => 'not_found'];

                continue;
            }

            // Per-widget permission check
            foreach ($widget->permissions() as $perm) {
                if (!$user->hasPermission($perm)) {
                    $results[] = ['key' => $req['key'], 'error' => 'forbidden'];

                    continue 2;
                }
            }

            // Company required for company scope
            if ($req['scope'] === 'company' && empty($req['company_id'])) {
                $results[] = ['key' => $req['key'], 'error' => 'company_id_required'];

                continue;
            }

            try {
                $data = $widget->resolve($req);
                $results[] = ['key' => $req['key'], 'data' => $data];
            } catch (RuntimeException $e) {
                $results[] = ['key' => $req['key'], 'error' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results]);
    }
}
