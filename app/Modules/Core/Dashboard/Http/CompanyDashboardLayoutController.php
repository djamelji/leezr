<?php

namespace App\Modules\Core\Dashboard\Http;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\CompanyDashboardLayout;
use App\Modules\Dashboard\CompanyDashboardWidgetSuggestion;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Modules\Dashboard\LayoutValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyDashboardLayoutController extends Controller
{
    /**
     * GET /api/company/dashboard/layout
     *
     * Resolves per-user layout, falling back to company default (ADR-326).
     */
    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $user = $request->user();
        $membership = $user->membershipFor($company);

        $layout = CompanyDashboardLayout::resolveForUser(
            $company->id,
            $user->id,
            $membership?->company_role_id
        );
        // ADR-816 : une disposition enregistrée ne montre que les widgets du catalogue AUTORISÉ de
        // l'utilisateur (même filtre que batchResolve) ; la disposition stockée n'est pas modifiée.
        $isOwner = $user->isOwnerOf($company);
        $allowed = collect(\App\Modules\Dashboard\DashboardCatalogService::forArchetype(
            $company,
            $membership?->companyRole?->archetype,
            $isOwner ? [] : ($membership?->companyRole?->effectivePermissionKeys() ?? []),
            $isOwner,
        ))->map(fn ($w) => $w->key())->all();

        $tiles = array_values(array_filter($layout?->layout_json ?? [], fn ($t) => in_array($t['key'] ?? null, $allowed, true)));

        return response()->json([
            'layout' => DashboardWidgetRegistry::filterLayout($tiles, $company), // filtre module + compaction
            // ADR-816 : une disposition existe en base (même si le filtrage la vide) → l'interface ne doit
            // pas la remplacer par une disposition par défaut lors d'un simple affichage.
            'stored' => $layout !== null,
        ]);
    }

    /**
     * PUT /api/company/dashboard/layout
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
            'layout.*.scope' => ['required', 'in:company'],
            'layout.*.config' => ['nullable', 'array'],
        ]);

        $validation = LayoutValidator::validate($validated['layout']);

        if (!$validation['valid']) {
            return response()->json([
                'message' => 'Invalid layout.',
                'errors' => $validation['errors'],
            ], 422);
        }

        $companyId = $request->attributes->get('company')->id;
        $userId = $request->user()->id;

        CompanyDashboardLayout::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $userId],
            ['layout_json' => $validated['layout']],
        );

        return response()->json(['layout' => $validated['layout']]);
    }

    /**
     * GET /api/company/dashboard/suggestions
     */
    public function suggestions(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company')->id;

        $suggestions = CompanyDashboardWidgetSuggestion::where('company_id', $companyId)
            ->where('status', 'pending')
            ->get(['id', 'module_key', 'widget_key', 'created_at']);

        return response()->json(['suggestions' => $suggestions]);
    }
}
