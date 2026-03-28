<?php

namespace Database\Seeders;

use App\Core\Automation\AutomationRule;
use Illuminate\Database\Seeder;

/**
 * ADR-425: Seed default automation rules.
 * Idempotent via updateOrCreate on key.
 */
class AutomationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            // ── Documents ──────────────────────────────────
            [
                'key' => 'documents.auto_remind',
                'label' => 'Document auto-remind',
                'description' => 'Send reminders for pending document requests past the configured threshold.',
                'category' => 'documents',
                'schedule' => '0 9 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'documents.auto_renew',
                'label' => 'Document auto-renew',
                'description' => 'Automatically create renewal requests for documents expiring soon.',
                'category' => 'documents',
                'schedule' => '0 8 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'documents.check_expiration',
                'label' => 'Document expiration check',
                'description' => 'Check for expired documents and send notifications to company admins.',
                'category' => 'documents',
                'schedule' => '0 0 * * *',
                'enabled' => true,
            ],

            // ── Billing ────────────────────────────────────
            [
                'key' => 'billing.retry_payment',
                'label' => 'Dunning payment retry',
                'description' => 'Retry failed payments according to the dunning escalation policy.',
                'category' => 'billing',
                'schedule' => '0 0 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.renew_subscriptions',
                'label' => 'Subscription auto-renewal',
                'description' => 'Renew subscriptions that have reached their billing cycle end date.',
                'category' => 'billing',
                'schedule' => '0 0 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.recover_webhooks',
                'label' => 'Webhook recovery',
                'description' => 'Recover missed payment provider webhooks by polling for unconfirmed events.',
                'category' => 'billing',
                'schedule' => '*/10 * * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.recover_checkouts',
                'label' => 'Checkout recovery',
                'description' => 'Recover abandoned checkout sessions by checking provider status.',
                'category' => 'billing',
                'schedule' => '*/10 * * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.check_expiring_cards',
                'label' => 'Expiring card notifications',
                'description' => 'Notify companies whose payment card is about to expire.',
                'category' => 'billing',
                'schedule' => '0 0 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.check_trial_expiring',
                'label' => 'Trial expiry notifications',
                'description' => 'Notify companies whose trial period is about to end.',
                'category' => 'billing',
                'schedule' => '0 0 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.expire_trials',
                'label' => 'Trial auto-expiration',
                'description' => 'Automatically expire trial subscriptions past their end date.',
                'category' => 'billing',
                'schedule' => '0 0 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.collect_scheduled',
                'label' => 'Scheduled debit collection',
                'description' => 'Collect SEPA scheduled debits that are due for processing.',
                'category' => 'billing',
                'schedule' => '0 6 * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.check_dlq',
                'label' => 'Dead letter queue check',
                'description' => 'Monitor the billing dead letter queue for stuck or failed webhook events.',
                'category' => 'billing',
                'schedule' => '0 * * * *',
                'enabled' => true,
            ],
            [
                'key' => 'billing.reconcile',
                'label' => 'Billing reconciliation',
                'description' => 'Weekly reconciliation of billing state with payment provider records.',
                'category' => 'billing',
                'schedule' => '0 0 * * 0',
                'enabled' => true,
            ],

            // ── System ─────────────────────────────────────
            [
                'key' => 'fx.refresh_rates',
                'label' => 'FX rate refresh',
                'description' => 'Refresh foreign exchange rates from the configured provider.',
                'category' => 'system',
                'schedule' => '0 */6 * * *',
                'enabled' => true,
            ],
        ];

        foreach ($rules as $rule) {
            AutomationRule::updateOrCreate(
                ['key' => $rule['key']],
                $rule,
            );
        }
    }
}
