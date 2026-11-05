<?php

namespace App\Modules\Workforce\Planning\Http;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\Department;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catalogue des catégories de couverture (Smart Planning Lot A, ADR-710). Scopé company. Écriture = coverage_manage.
 */
class CoverageCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            CoverageCategory::withoutGlobalScopes()
                ->where('company_id', $request->attributes->get('company_id'))
                ->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $companyId = $request->attributes->get('company_id');

        if (CoverageCategory::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $data['code'])->exists()) {
            return response()->json(['message' => "A coverage category with code '{$data['code']}' already exists."], 422);
        }
        if (! empty($data['department_id']) && ! $this->deptOk($companyId, $data['department_id'])) {
            return response()->json(['message' => 'Department does not belong to this company.'], 422);
        }

        $cat = CoverageCategory::create(array_merge($data, ['company_id' => $companyId]));
        app(AuditLogger::class)->logCompany($companyId, 'coverage_category.created', 'coverage_category', (string) $cat->id, ['metadata' => ['category' => 'workforce.planning', 'code' => $cat->code]]);

        return response()->json($cat, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $cat = CoverageCategory::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        $data = $this->validatePayload($request, false);

        if (! empty($data['department_id']) && ! $this->deptOk($cat->company_id, $data['department_id'])) {
            return response()->json(['message' => 'Department does not belong to this company.'], 422);
        }
        if (isset($data['code']) && $data['code'] !== $cat->code
            && CoverageCategory::withoutGlobalScopes()->where('company_id', $cat->company_id)->where('code', $data['code'])->exists()) {
            return response()->json(['message' => 'Code already used.'], 422);
        }

        $cat->fill($data)->save();

        return response()->json($cat);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $cat = CoverageCategory::withoutGlobalScopes()
            ->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
        $cat->delete();

        return response()->json(['message' => 'Coverage category deleted.']);
    }

    private function deptOk(int $companyId, int $deptId): bool
    {
        return Department::withoutGlobalScopes()->where('id', $deptId)->where('company_id', $companyId)->exists();
    }

    private function validatePayload(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'code' => ($creating ? 'required' : 'nullable').'|string|max:40',
            'name' => ($creating ? 'required' : 'nullable').'|string|max:120',
            'color' => 'nullable|string|max:7',
            'resource_type' => 'nullable|string|max:40',
            'department_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);
    }
}
