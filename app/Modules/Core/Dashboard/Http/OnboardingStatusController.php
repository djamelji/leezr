<?php

namespace App\Modules\Core\Dashboard\Http;

use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Subscription;
use App\Core\Documents\CompanyDocument;
use App\Core\Documents\MemberDocument;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-383: Onboarding widget — owner-only, dismissible, 6 steps.
 * Replaces ADR-372 permission-filtered approach with owner-exclusive access.
 */
class OnboardingStatusController
{
    private function stepDefinitions(Company $company): array
    {
        $steps = [
            [
                'key' => 'account_created',
                'completed' => true,
            ],
            [
                'key' => 'company_profile',
                'completed' => FieldValue::where('model_type', $company->getMorphClass())
                    ->where('model_id', $company->id)
                    ->whereHas('definition', fn ($q) => $q->where('scope', FieldDefinition::SCOPE_COMPANY))
                    ->whereNotNull('value')
                    ->exists(),
            ],
        ];

        // Audit post-prod: the "add a payment method" step is only APPLICABLE when the company's
        // provider can actually register a card online (Stripe — same signal as can_pay_online). With
        // provider null/internal it is technically impossible, so we EXCLUDE it from the checklist
        // (and thus the denominator) instead of leaving the owner permanently stuck at e.g. 5/6.
        if ($this->canRegisterCard($company)) {
            $steps[] = [
                'key' => 'payment_method',
                'completed' => CompanyPaymentProfile::where('company_id', $company->id)->exists(),
            ];
        }

        $steps[] = [
            'key' => 'invite_member',
            'completed' => $company->memberships()->count() > 1,
        ];
        $steps[] = [
            'key' => 'activate_module',
            'completed' => CompanyModule::where('company_id', $company->id)
                ->where('is_enabled_for_company', true)
                ->exists(),
        ];
        $steps[] = [
            'key' => 'first_document',
            'completed' => CompanyDocument::where('company_id', $company->id)->exists()
                || MemberDocument::where('company_id', $company->id)->exists(),
        ];

        return $steps;
    }

    /** True only when the company's current provider can collect online (card registration possible). */
    private function canRegisterCard(Company $company): bool
    {
        return Subscription::where('company_id', $company->id)
            ->where('is_current', true)
            ->value('provider') === 'stripe';
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();

        if (! $user->isOwnerOf($company)) {
            return response()->json(['message' => 'Owner only.'], 403);
        }

        if ($company->onboarding_dismissed_at !== null) {
            // Return dismissed + steps so frontend can show reopen banner when not all completed
            $steps = $this->stepDefinitions($company);

            $output = array_map(fn (array $s) => [
                'key' => $s['key'],
                'completed' => $s['completed'],
            ], $steps);

            $completedCount = collect($output)->where('completed', true)->count();

            return response()->json([
                'dismissed' => true,
                'steps' => $output,
                'completed_count' => $completedCount,
                'total_count' => count($output),
            ]);
        }

        $steps = $this->stepDefinitions($company);

        $output = array_map(fn (array $s) => [
            'key' => $s['key'],
            'completed' => $s['completed'],
        ], $steps);

        $completedCount = collect($output)->where('completed', true)->count();

        return response()->json([
            'steps' => $output,
            'completed_count' => $completedCount,
            'total_count' => count($output),
        ]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();

        if (! $user->isOwnerOf($company)) {
            return response()->json(['message' => 'Owner only.'], 403);
        }

        $company->update(['onboarding_dismissed_at' => now()]);

        return response()->json(['dismissed' => true]);
    }

    public function reopen(Request $request): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');
        $user = $request->user();

        if (! $user->isOwnerOf($company)) {
            return response()->json(['message' => 'Owner only.'], 403);
        }

        $company->update(['onboarding_dismissed_at' => null]);

        // Return fresh steps (same as __invoke)
        return $this->__invoke($request);
    }
}
