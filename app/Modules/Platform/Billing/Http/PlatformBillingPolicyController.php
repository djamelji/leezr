<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Billing\PlatformBillingPolicy;
use App\Modules\Platform\Billing\UseCases\UpdateBillingPolicyUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-135 D0: Singleton billing policy governance.
 *
 * GET  — read current policy state
 * PUT  — update with hard validation + audit diff
 *
 * Requires manage_billing permission.
 */
class PlatformBillingPolicyController
{
    private const UPGRADE_TIMING = ['immediate', 'end_of_period', 'end_of_trial'];
    private const DOWNGRADE_TIMING = ['immediate', 'end_of_period'];
    private const INTERVAL_CHANGE_TIMING = ['immediate', 'end_of_period'];
    private const PRORATION_STRATEGY = ['day_based', 'none'];
    private const TAX_MODE = ['exclusive', 'inclusive'];
    private const FAILURE_ACTION = ['suspend', 'downgrade_to_starter', 'read_only'];
    private const ADDON_BILLING_INTERVAL = ['monthly', 'plan_aligned'];

    public function show(): JsonResponse
    {
        return response()->json([
            'policy' => PlatformBillingPolicy::instance(),
        ]);
    }

    public function update(Request $request, UpdateBillingPolicyUseCase $useCase): JsonResponse
    {
        $validated = $request->validate([
            // Wallet
            'allow_negative_wallet' => ['sometimes', 'boolean'],
            'auto_apply_wallet_credit' => ['sometimes', 'boolean'],

            // Plan change timing
            'upgrade_timing' => ['sometimes', 'string', Rule::in(self::UPGRADE_TIMING)],
            'downgrade_timing' => ['sometimes', 'string', Rule::in(self::DOWNGRADE_TIMING)],
            'interval_change_timing' => ['sometimes', 'string', Rule::in(self::INTERVAL_CHANGE_TIMING)],
            'proration_strategy' => ['sometimes', 'string', Rule::in(self::PRORATION_STRATEGY)],

            // Dunning
            'grace_period_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'max_retry_attempts' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'retry_intervals_days' => ['sometimes', 'array'],
            'retry_intervals_days.*' => ['integer', 'min:1', 'max:90'],
            'failure_action' => ['sometimes', 'string', Rule::in(self::FAILURE_ACTION)],

            // Invoice
            'invoice_due_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'invoice_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Z0-9\-]+$/'],
            'invoice_next_number' => ['sometimes', 'integer', 'min:1'],
            'credit_note_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Z0-9\-]+$/'],
            'credit_note_next_number' => ['sometimes', 'integer', 'min:1'],

            // Tax
            'tax_mode' => ['sometimes', 'string', Rule::in(self::TAX_MODE)],
            'default_tax_rate_bps' => ['sometimes', 'integer', 'min:0', 'max:10000'],

            // Trial
            'free_trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            // Addon
            'addon_billing_interval' => ['sometimes', 'string', Rule::in(self::ADDON_BILLING_INTERVAL)],
        ]);

        $policy = $useCase->execute($validated);

        return response()->json([
            'message' => 'Billing policy updated.',
            'policy' => $policy,
        ]);
    }
}
