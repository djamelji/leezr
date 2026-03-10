<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\BillingCoupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponCrudController
{
    public function index(): JsonResponse
    {
        $coupons = BillingCoupon::orderByDesc('created_at')->get();

        return response()->json(['coupons' => $coupons]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:billing_coupons,code',
            'name' => 'required|string|max:255',
            'type' => 'required|in:percentage,fixed_amount',
            'value' => ['required', 'integer', 'min:1', $request->input('type') === 'percentage' ? 'max:10000' : 'max:99999999'],
            'currency' => 'nullable|string|size:3',
            'max_uses' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:1000',
            'max_uses_per_company' => 'nullable|integer|min:1',
            'applicable_billing_cycles' => 'nullable|array',
            'applicable_billing_cycles.*' => 'string|in:monthly,yearly',
            'applicable_addon_keys' => 'nullable|array',
            'applicable_addon_keys.*' => 'string',
            'addon_mode' => 'nullable|string|in:include,exclude',
            'duration_months' => 'nullable|integer|min:0',
            'first_purchase_only' => 'boolean',
            'applicable_plan_keys' => 'nullable|array',
            'applicable_plan_keys.*' => 'string',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'is_active' => 'boolean',
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $coupon = BillingCoupon::create($validated);

        return response()->json(['coupon' => $coupon], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $coupon = BillingCoupon::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'value' => ['sometimes', 'integer', 'min:1', ($request->input('type') ?? $coupon->type) === 'percentage' ? 'max:10000' : 'max:99999999'],
            'max_uses' => 'nullable|integer|min:1',
            'applicable_plan_keys' => 'nullable|array',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
            'description' => 'nullable|string|max:1000',
            'max_uses_per_company' => 'nullable|integer|min:1',
            'applicable_billing_cycles' => 'nullable|array',
            'applicable_billing_cycles.*' => 'string|in:monthly,yearly',
            'applicable_addon_keys' => 'nullable|array',
            'applicable_addon_keys.*' => 'string',
            'addon_mode' => 'nullable|string|in:include,exclude',
            'duration_months' => 'nullable|integer|min:0',
            'first_purchase_only' => 'sometimes|boolean',
        ]);

        $coupon->update($validated);

        return response()->json(['coupon' => $coupon->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $coupon = BillingCoupon::findOrFail($id);

        if ($coupon->used_count > 0) {
            $coupon->update(['is_active' => false]);

            return response()->json(['message' => 'Coupon deactivated (has usages).']);
        }

        $coupon->delete();

        return response()->json(['message' => 'Coupon deleted.']);
    }
}
