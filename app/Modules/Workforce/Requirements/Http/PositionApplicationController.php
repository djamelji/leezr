<?php

namespace App\Modules\Workforce\Requirements\Http;

use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Requirements\PositionDocumentApplication;
use App\Modules\Workforce\Security\WorkforceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-819 (Company 2.0) — people of a position, state of their file, explicit application of its requirements. */
class PositionApplicationController
{
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->service($request)->people($this->job($request, $id), $this->visible($request)));
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['employee_ids' => 'required|array|min:1|max:500', 'employee_ids.*' => 'integer']);

        return response()->json($this->service($request)->apply($this->job($request, $id), $data['employee_ids'], $this->visible($request)));
    }

    private function service(Request $request): PositionDocumentApplication
    {
        return new PositionDocumentApplication($request->attributes->get('company'), $request->user());
    }

    private function job(Request $request, int $id): JobRole
    {
        return JobRole::withoutGlobalScopes()->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);
    }

    private function visible(Request $request): ?array
    {
        return WorkforceAccess::hasViewAll($request, 'employee') ? null : WorkforceAccess::visibleEmployeeIds($request, 'employee');
    }
}
