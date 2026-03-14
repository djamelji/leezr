<?php

namespace App\Core\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Singleton billing policy configuration.
 * Same pattern as PlatformSetting::instance().
 *
 * All billing behavior parameters are typed columns — not a JSON blob.
 * Cached for 1 hour via Cache::remember (ADR-312).
 */
class PlatformBillingPolicy extends Model
{
    private const CACHE_KEY = 'platform_billing_policy';

    private const CACHE_TTL = 3600; // 1 hour
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
        'addon_deactivation_timing',
        'trial_plan_change_behavior',
        'trial_requires_payment_method',
        'trial_charge_timing',
        'trial_expiry_notification_days',
        'payment_method_expiry_check_days',
        'reconciliation_lookback_days',
        'default_billing_interval',
        'allow_sepa',
        'sepa_requires_trial',
        'sepa_first_failure_action',
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
            'trial_expiry_notification_days' => 'integer',
            'payment_method_expiry_check_days' => 'integer',
            'reconciliation_lookback_days' => 'integer',
            'allow_sepa' => 'boolean',
            'sepa_requires_trial' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::clearCache());
        static::deleted(fn () => static::clearCache());
    }

    /**
     * Singleton access — always returns the single row.
     * Creates it with defaults on first call.
     * Cached for 1 hour (ADR-312).
     */
    public static function instance(): static
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
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
        });
    }

    /**
     * Clear the cached singleton (call after update).
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
