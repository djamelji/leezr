<?php

namespace App\Modules\Platform\Billing\Http;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\DiffEngine;
use App\Core\Billing\PlatformBillingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    private const PRORATION_STRATEGY = ['day_based', 'none'];
    private const TAX_MODE = ['none', 'inclusive', 'exclusive'];
    private const FAILURE_ACTION = ['suspend', 'downgrade_to_starter', 'read_only'];
    private const ADDON_BILLING_INTERVAL = ['monthly', 'plan_aligned'];

    public function show(): JsonResponse
    {
        return response()->json([
            'policy' => PlatformBillingPolicy::instance(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            // Wallet
            'wallet_first' => ['sometimes', 'boolean'],
            'allow_negative_wallet' => ['sometimes', 'boolean'],
            'auto_apply_wallet_credit' => ['sometimes', 'boolean'],

            // Plan change timing
            'upgrade_timing' => ['sometimes', 'string', Rule::in(self::UPGRADE_TIMING)],
            'downgrade_timing' => ['sometimes', 'string', Rule::in(self::DOWNGRADE_TIMING)],
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

        if (empty($validated)) {
            return response()->json(['message' => 'No fields to update.'], 422);
        }

        return DB::transaction(function () use ($validated, $audit) {
            $policy = PlatformBillingPolicy::instance();
            $before = $policy->toArray();

            // ── Cannot-decrease guard for next_number fields ──
            if (isset($validated['invoice_next_number']) && $validated['invoice_next_number'] < $policy->invoice_next_number) {
                return response()->json([
                    'message' => 'invoice_next_number cannot decrease.',
                    'errors' => ['invoice_next_number' => ['Cannot decrease below current value ('.$policy->invoice_next_number.').']]
                ], 422);
            }

            if (isset($validated['credit_note_next_number']) && $validated['credit_note_next_number'] < $policy->credit_note_next_number) {
                return response()->json([
                    'message' => 'credit_note_next_number cannot decrease.',
                    'errors' => ['credit_note_next_number' => ['Cannot decrease below current value ('.$policy->credit_note_next_number.').']]
                ], 422);
            }

            // ── retry_intervals_days: strictly increasing + length == max_retry_attempts ──
            $maxRetries = $validated['max_retry_attempts'] ?? $policy->max_retry_attempts;
            $retryIntervals = $validated['retry_intervals_days'] ?? $policy->retry_intervals_days;

            if (isset($validated['retry_intervals_days']) || isset($validated['max_retry_attempts'])) {
                if (count($retryIntervals) !== $maxRetries) {
                    return response()->json([
                        'message' => 'retry_intervals_days length must equal max_retry_attempts.',
                        'errors' => ['retry_intervals_days' => ["Expected {$maxRetries} entries, got ".count($retryIntervals).'.']]
                    ], 422);
                }

                for ($i = 1; $i < count($retryIntervals); $i++) {
                    if ($retryIntervals[$i] <= $retryIntervals[$i - 1]) {
                        return response()->json([
                            'message' => 'retry_intervals_days must be strictly increasing.',
                            'errors' => ['retry_intervals_days' => ['Values must be strictly increasing.']]
                        ], 422);
                    }
                }
            }

            $policy->update($validated);
            $policy->refresh();

            $after = $policy->toArray();
            $diff = DiffEngine::diff($before, $after);

            if (! DiffEngine::isEmpty($diff)) {
                $audit->logPlatform(
                    AuditAction::BILLING_POLICY_UPDATED,
                    'platform_billing_policy',
                    (string) $policy->id,
                    [
                        'diffBefore' => $before,
                        'diffAfter' => $after,
                        'metadata' => ['diff' => $diff],
                    ],
                );
            }

            return response()->json([
                'message' => 'Billing policy updated.',
                'policy' => $policy,
            ]);
        });
    }
}
