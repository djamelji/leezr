<?php

namespace App\Modules\Platform\Billing\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\DiffEngine;
use App\Core\Billing\PlatformBillingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBillingPolicyUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Update the billing policy singleton with invariant checks and audit.
     *
     * Invariants:
     * - invoice_next_number / credit_note_next_number cannot decrease
     * - retry_intervals_days length must equal max_retry_attempts
     * - retry_intervals_days must be strictly increasing
     */
    public function execute(array $validated): PlatformBillingPolicy
    {
        if (empty($validated)) {
            throw ValidationException::withMessages([
                'policy' => ['No fields to update.'],
            ]);
        }

        return DB::transaction(function () use ($validated) {
            $policy = PlatformBillingPolicy::instance();
            $before = $policy->toArray();

            $this->guardCannotDecrease($policy, $validated, 'invoice_next_number');
            $this->guardCannotDecrease($policy, $validated, 'credit_note_next_number');
            $this->guardRetryIntervals($policy, $validated);

            $policy->update($validated);
            $policy->refresh();

            $after = $policy->toArray();
            $diff = DiffEngine::diff($before, $after);

            if (! DiffEngine::isEmpty($diff)) {
                $this->audit->logPlatform(
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

            return $policy;
        });
    }

    private function guardCannotDecrease(PlatformBillingPolicy $policy, array $validated, string $field): void
    {
        if (isset($validated[$field]) && $validated[$field] < $policy->{$field}) {
            throw ValidationException::withMessages([
                $field => ["Cannot decrease below current value ({$policy->{$field}})."],
            ]);
        }
    }

    private function guardRetryIntervals(PlatformBillingPolicy $policy, array $validated): void
    {
        if (! isset($validated['retry_intervals_days']) && ! isset($validated['max_retry_attempts'])) {
            return;
        }

        $maxRetries = $validated['max_retry_attempts'] ?? $policy->max_retry_attempts;
        $retryIntervals = $validated['retry_intervals_days'] ?? $policy->retry_intervals_days;

        if (count($retryIntervals) !== $maxRetries) {
            throw ValidationException::withMessages([
                'retry_intervals_days' => ["Expected {$maxRetries} entries, got " . count($retryIntervals) . '.'],
            ]);
        }

        for ($i = 1; $i < count($retryIntervals); $i++) {
            if ($retryIntervals[$i] <= $retryIntervals[$i - 1]) {
                throw ValidationException::withMessages([
                    'retry_intervals_days' => ['Values must be strictly increasing.'],
                ]);
            }
        }
    }
}
