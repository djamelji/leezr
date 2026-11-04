<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\CoverageRequirement;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\CoverageReadModel;
use App\Modules\Workforce\UseCases\CreateCoverageRequirementUseCase;
use App\Modules\Workforce\UseCases\UpdateCoverageRequirementUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Besoins de personnel (coverage) — Smart Planning SP1a (ADR-705). Toujours scopé company.
 */
class CoverageRequirementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json(
            CoverageRequirement::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->with(['workLocation', 'department'])
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get()
        );
    }

    /** Couverture calculée (besoin vs affecté vs écart) sur une plage — pour la ligne de synthèse de la grille. */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json(
            CoverageReadModel::forRange($request->attributes->get('company_id'), $validated['from'], $validated['to'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        try {
            $req = app(CreateCoverageRequirementUseCase::class)->execute($request->attributes->get('company_id'), $validated);

            return response()->json($req, 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $req = CoverageRequirement::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $this->validatePayload($request, false);

        try {
            return response()->json(app(UpdateCoverageRequirementUseCase::class)->execute($req, $validated));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $req = CoverageRequirement::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $req->delete();

        return response()->json(['message' => 'Coverage requirement deleted.']);
    }

    private function validatePayload(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'label' => 'nullable|string|max:120',
            'date' => 'nullable|date',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'department_id' => 'nullable|integer',
            'work_location_id' => 'nullable|integer',
            'activity_type' => 'nullable|string|max:60',
            'headcount' => ($creating ? 'required' : 'nullable').'|integer|min:1|max:999',
            'priority' => 'nullable|in:low,normal,high,critical',
            'enabled' => 'nullable|boolean',
        ]);
    }
}
