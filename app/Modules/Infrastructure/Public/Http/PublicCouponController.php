<?php

namespace App\Modules\Infrastructure\Public\Http;

use App\Modules\Core\Billing\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/public/validate-coupon
 *
 * Public endpoint for validating a coupon code during registration.
 * Rate limited: 10 req/min per IP.
 */
class PublicCouponController
{
    public function __invoke(Request $request, CouponService $couponService): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50',
            'plan_key' => 'required|string',
            'subtotal_cents' => 'sometimes|integer|min:0',
        ]);

        // For public validation (no company yet), we create a temporary stub
        // to check plan applicability only — no company-specific checks
        $result = $couponService->validate(
            $validated['code'],
            new \App\Core\Models\Company(), // empty stub for public validation
            $validated['plan_key'],
            subtotalCents: $validated['subtotal_cents'] ?? 0,
        );

        return response()->json([
            'valid' => $result['valid'],
            'error' => $result['error'],
            'discount_preview' => $result['discount_preview'],
            'discount_type' => $result['coupon']?->type,
            'discount_value' => $result['coupon']?->value,
            'coupon_name' => $result['coupon']?->name,
        ]);
    }
}
