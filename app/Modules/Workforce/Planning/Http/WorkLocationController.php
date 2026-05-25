<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Workforce\WorkLocation;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\WorkLocationReadModel;
use App\Modules\Workforce\UseCases\CreateWorkLocationUseCase;
use App\Modules\Workforce\UseCases\UpdateWorkLocationUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json(
            $request->boolean('all')
                ? WorkLocationReadModel::all($companyId)
                : WorkLocationReadModel::forCompany($companyId)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'type' => 'nullable|string|in:' . implode(',', WorkLocation::TYPES),
            'client_name' => 'nullable|string|max:100',
            'client_reference' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:50',
        ]);

        try {
            $location = app(CreateWorkLocationUseCase::class)->execute(
                companyId: $request->attributes->get('company_id'),
                name: $validated['name'],
                type: $validated['type'] ?? WorkLocation::TYPE_INTERNAL,
                clientName: $validated['client_name'] ?? null,
                clientReference: $validated['client_reference'] ?? null,
                address: $validated['address'] ?? null,
                timezone: $validated['timezone'] ?? null,
            );

            return response()->json($location, 201);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $location = WorkLocation::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:100',
            'type' => 'nullable|string|in:' . implode(',', WorkLocation::TYPES),
            'client_name' => 'nullable|string|max:100',
            'client_reference' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:50',
            'enabled' => 'nullable|boolean',
        ]);

        try {
            return response()->json(app(UpdateWorkLocationUseCase::class)->execute($location, $validated));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $location = WorkLocation::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        if ($location->shifts()->exists()) {
            return response()->json(['message' => 'Cannot delete a location with existing shifts.'], 422);
        }

        $location->delete();

        return response()->json(['message' => 'Location deleted.']);
    }
}
