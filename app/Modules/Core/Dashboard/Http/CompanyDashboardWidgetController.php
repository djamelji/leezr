<?php

namespace App\Modules\Core\Dashboard\Http;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\DashboardCatalogService;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CompanyDashboardWidgetController extends Controller
{
    /**
     * GET /api/company/dashboard/widgets/catalog
     */
    public function catalog(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();
        $membership = $user->membershipFor($company);
        $archetype = $membership?->companyRole?->archetype;
        $isOwner = $user->isOwnerOf($company);
        $userPermissions = $isOwner ? [] : ($membership?->companyRole?->permissions->pluck('key')->all() ?? []);

        $widgets = DashboardCatalogService::forArchetype($company, $archetype, $userPermissions, $isOwner);

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
                'resolution' => $w->resolution(),
            ])->values()->all(),
        ]);
    }

    /**
     * POST /api/company/dashboard/widgets/data
     *
     * Batch resolve widgets. Always company-scoped (company_id from context).
     * ADR-371: Only resolves widgets the user has access to (filtered by catalog).
     */
    public function batchResolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'widgets' => ['required', 'array', 'min:1', 'max:20'],
            'widgets.*.key' => ['required', 'string'],
            'widgets.*.period' => ['nullable', 'in:7d,30d,90d'],
        ]);

        $company = $request->attributes->get('company');
        $user = $request->user();
        $membership = $user->membershipFor($company);
        $archetype = $membership?->companyRole?->archetype;
        $isOwner = $user->isOwnerOf($company);
        $userPermissions = $isOwner ? [] : ($membership?->companyRole?->permissions->pluck('key')->all() ?? []);

        // Build allowed widget keys from filtered catalog
        $allowedKeys = collect(DashboardCatalogService::forArchetype($company, $archetype, $userPermissions, $isOwner))
            ->map(fn ($w) => $w->key())
            ->all();

        $results = [];

        foreach ($validated['widgets'] as $req) {
            // Reject widgets not in user's catalog
            if (!in_array($req['key'], $allowedKeys, true)) {
                $results[] = ['key' => $req['key'], 'error' => 'not_found'];

                continue;
            }

            $widget = DashboardWidgetRegistry::find($req['key']);

            if (!$widget) {
                $results[] = ['key' => $req['key'], 'error' => 'not_found'];

                continue;
            }

            try {
                $data = $widget->resolve([
                    'scope' => 'company',
                    'company_id' => $company->id,
                    'period' => $req['period'] ?? '30d',
                ]);
                $results[] = ['key' => $req['key'], 'data' => $data];
            } catch (RuntimeException $e) {
                $results[] = ['key' => $req['key'], 'error' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results]);
    }
}
