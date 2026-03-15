<?php

namespace App\Core\Billing\Dunning;

use App\Core\Billing\Invoice;
use App\Core\Models\Company;
use App\Core\Notifications\NotificationDispatcher;
use App\Notifications\Billing\AccountSuspended;
use App\Notifications\Billing\PaymentFailed;
use Illuminate\Support\Facades\Log;

/**
 * Notification logic for the dunning engine.
 *
 * Handles payment failure and account suspension notifications.
 * All methods are wrapped in try/catch for graceful failure.
 */
class DunningNotifier
{
    /**
     * ADR-226: Notify company owner of payment failure.
     */
    public static function notifyPaymentFailed(Invoice $invoice): void
    {
        try {
            $company = $invoice->company;
            $owner = $company?->owner();

            if ($owner) {
                NotificationDispatcher::send(
                    topicKey: 'billing.payment_failed',
                    recipients: [$owner],
                    payload: ['invoice_id' => $invoice->id, 'amount' => $invoice->formatted_total],
                    company: $company,
                    mailNotification: new PaymentFailed($invoice),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[dunning] Failed to send PaymentFailed notification', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ADR-226: Notify company owner of account suspension.
     */
    public static function notifyAccountSuspended(Company $company): void
    {
        try {
            $owner = $company->owner();

            if ($owner) {
                NotificationDispatcher::send(
                    topicKey: 'billing.account_suspended',
                    recipients: [$owner],
                    payload: ['company_id' => $company->id, 'company_name' => $company->name],
                    company: $company,
                    mailNotification: new AccountSuspended(),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[dunning] Failed to send AccountSuspended notification', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
