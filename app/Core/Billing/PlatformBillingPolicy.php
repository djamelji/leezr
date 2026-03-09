<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton billing policy configuration.
 * Same pattern as PlatformSetting::instance().
 *
 * All billing behavior parameters are typed columns — not a JSON blob.
 */
class PlatformBillingPolicy extends Model
{
    protected $table = 'platform_billing_policies';

    protected $fillable = [
        'allow_negative_wallet', 'auto_apply_wallet_credit',
        'upgrade_timing', 'downgrade_timing', 'interval_change_timing', 'proration_strategy',
        'grace_period_days', 'max_retry_attempts', 'retry_intervals_days', 'failure_action',
        'invoice_due_days', 'invoice_prefix', 'invoice_next_number',
        'credit_note_prefix', 'credit_note_next_number',
        'tax_mode', 'default_tax_rate_bps',
        'admin_approval_required',
        'addon_billing_interval',
        'trial_plan_change_behavior',
        'trial_requires_payment_method',
        'trial_charge_timing',
    ];

    protected function casts(): array
    {
        return [
            'allow_negative_wallet' => 'boolean',
            'auto_apply_wallet_credit' => 'boolean',
            'grace_period_days' => 'integer',
            'max_retry_attempts' => 'integer',
            'retry_intervals_days' => 'array',
            'invoice_due_days' => 'integer',
            'invoice_next_number' => 'integer',
            'credit_note_next_number' => 'integer',
            'default_tax_rate_bps' => 'integer',
            'admin_approval_required' => 'boolean',
            'trial_requires_payment_method' => 'boolean',
        ];
    }

    /**
     * Singleton access — always returns the single row.
     * Creates it with defaults on first call.
     */
    public static function instance(): static
    {
        $count = static::query()->count();

        if ($count > 1) {
            throw new \RuntimeException(
                "PlatformBillingPolicy singleton violated: {$count} rows found. Expected exactly 1."
            );
        }

        $existing = static::query()->first();

        if ($existing) {
            return $existing;
        }

        static::create([
            'retry_intervals_days' => [1, 3, 7],
        ]);

        // Re-fetch to get DB-applied defaults
        return static::query()->first();
    }
}
