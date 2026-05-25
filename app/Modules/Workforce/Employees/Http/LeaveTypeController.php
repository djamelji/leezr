<?php

namespace App\Modules\Workforce\Employees\Http;

use App\Core\Workforce\LeaveType;
use App\Http\Controllers\Controller;
use App\Modules\Workforce\ReadModels\LeaveTypeReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    private const TYPE_RULES = [
        'code' => 'required|string|max:20',
        'name' => 'required|string|max:100',
        'description' => 'nullable|string|max:500',
        'accrual_mode' => 'required|string|in:monthly,annual,none,custom',
        'annual_entitlement_hundredths' => 'nullable|integer|min:0',
        'max_balance_hundredths' => 'nullable|integer|min:0',
        'carry_over_hundredths' => 'nullable|integer|min:0',
        'carry_over_deadline_month' => 'nullable|integer|min:1|max:12',
        'requires_approval' => 'nullable|boolean',
        'is_paid' => 'nullable|boolean',
        'sort_order' => 'nullable|integer',
    ];

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');

        return response()->json(
            $request->boolean('all')
                ? LeaveTypeReadModel::allForCompany($companyId)
                : LeaveTypeReadModel::forCompany($companyId)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company_id');
        $validated = $request->validate(self::TYPE_RULES);

        $type = LeaveType::create(array_merge($validated, [
            'company_id' => $companyId,
            'is_system' => false,
            'enabled' => true,
        ]));

        return response()->json($type, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = LeaveType::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        if ($type->is_system) {
            return response()->json(['message' => 'Cannot modify a system leave type.'], 422);
        }

        $rules = collect(self::TYPE_RULES)
            ->map(fn ($rule) => str_replace('required|', 'nullable|', $rule))
            ->merge(['enabled' => 'nullable|boolean'])
            ->toArray();

        $type->update($request->validate($rules));

        return response()->json($type->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $type = LeaveType::withoutCompanyScope()
            ->where('company_id', $request->attributes->get('company_id'))
            ->findOrFail($id);

        if ($type->is_system) {
            return response()->json(['message' => 'Cannot delete a system leave type.'], 422);
        }
        if ($type->leaveRequests()->exists()) {
            return response()->json(['message' => 'Cannot delete a leave type with existing requests.'], 422);
        }

        $type->delete();

        return response()->json(['message' => 'Leave type deleted.']);
    }
}
