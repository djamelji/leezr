<?php

namespace App\Modules\Core\Dashboard\Http;

use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $steps = [
            [
                'key' => 'account_created',
                'completed' => true, // Always true — they are logged in
            ],
            [
                'key' => 'plan_selected',
                'completed' => Subscription::where('company_id', $company->id)
                    ->where('is_current', true)
                    ->exists(),
            ],
            [
                'key' => 'company_profile',
                'completed' => ! empty($company->address_line1) || ! empty($company->tax_id),
            ],
            [
                'key' => 'payment_method',
                'completed' => CompanyPaymentProfile::where('company_id', $company->id)->exists(),
            ],
            [
                'key' => 'invite_member',
                'completed' => $company->memberships()->count() > 1,
            ],
        ];

        $completedCount = collect($steps)->where('completed', true)->count();

        return response()->json([
            'steps' => $steps,
            'completed_count' => $completedCount,
            'total_count' => count($steps),
        ]);
    }
}
