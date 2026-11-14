<?php

namespace App\Core\Notifications\Listeners;

use App\Core\Billing\Payment;
use App\Core\Models\Company;
use App\Core\Notifications\NotificationDispatcher;
use App\Platform\Models\PlatformUser;
use Illuminate\Support\Facades\Log;

/**
 * ADR-781 (UX5) — SELLER-side notice : a customer company's payment has been collected.
 *
 * Fires once, when a Payment becomes `succeeded` (same condition as the ledger posting in
 * Payment::booted), to platform users holding the billing notification permission (the dispatcher
 * filters by NotificationTopicRegistry::PLATFORM_CATEGORY_PERMISSIONS). The customer company only
 * receives `billing.payment_received` (« payment made / invoice paid »). Non-fatal : a notification
 * failure never affects the payment, the invoice or the ledger.
 */
class PlatformPaymentCollectedNotifier
{
    public static function register(): void
    {
        Payment::saved(static function (Payment $payment): void {
            if ($payment->status !== 'succeeded' || ! $payment->company_id) {
                return;
            }
            if (! $payment->wasRecentlyCreated && ! $payment->wasChanged('status')) {
                return;
            }
            self::notify($payment);
        });
    }

    public static function notify(Payment $payment): int
    {
        try {
            // Idempotent per payment (a fresh model saved twice in one request keeps wasRecentlyCreated).
            $entityKey = "payment_collected:{$payment->id}";
            if ($payment->id && \App\Core\Notifications\NotificationEvent::where('topic_key', 'platform.payment_collected')->where('entity_key', $entityKey)->exists()) {
                return 0;
            }
            $company = Company::withoutGlobalScopes()->find($payment->company_id);
            $amount = number_format(((int) $payment->amount) / 100, 2, ',', ' ').' '.strtoupper((string) $payment->currency);

            return NotificationDispatcher::send(
                'platform.payment_collected',
                PlatformUser::all(),
                [
                    'company_name' => $company?->name ?? '—',
                    'amount' => $amount,
                    'invoice_number' => $payment->invoice?->number ?? '—',
                    'link' => '/platform/billing',
                ],
                entityKey: $payment->id ? $entityKey : null,
            );
        } catch (\Throwable $e) {
            Log::warning('[notifications] platform payment collected notice failed (non-fatal)', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
