<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * ADR-302: Confirm embedded payment after registration.
 *
 * Handles both:
 * - pending_payment: save card + activate subscription
 * - trialing: save card/SEPA for future billing (subscription stays trialing)
 */
class ConfirmRegistrationPaymentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_method_id' => 'required|string',
            'subscription_id' => 'required|integer',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Find the subscription (pending_payment or trialing)
        $subscription = Subscription::where('id', $data['subscription_id'])
            ->whereIn('status', ['pending_payment', 'trialing'])
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No pending subscription found.'], 404);
        }

        $company = $subscription->company;

        // Verify the user owns this company
        if (! $company->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Retrieve payment method from Stripe
        try {
            $adapter = app(StripePaymentAdapter::class);
            $pm = $adapter->retrievePaymentMethod($data['payment_method_id']);
        } catch (\Throwable $e) {
            Log::error('[billing] Confirm registration payment: failed to retrieve PM', [
                'company_id' => $company->id,
                'payment_method_id' => $data['payment_method_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not verify payment method.'], 422);
        }

        // Extract profile data based on type (card or sepa_debit)
        $type = $pm->type ?? 'card';

        if ($type === 'sepa_debit') {
            $sepa = $pm->sepa_debit;
            $profileData = [
                'method_key' => 'sepa_debit',
                'label' => 'SEPA •••• ' . ($sepa->last4 ?? '****'),
                'metadata' => [
                    'bank_code' => $sepa->bank_code ?? null,
                    'country' => $sepa->country ?? null,
                    'last4' => $sepa->last4 ?? null,
                    'fingerprint' => $sepa->fingerprint ?? null,
                    'holder_name' => $pm->billing_details?->name ?? null,
                ],
            ];
        } else {
            $card = $pm->card;
            $profileData = [
                'method_key' => 'card',
                'label' => ucfirst($card->brand ?? 'Card') . ' •••• ' . ($card->last4 ?? '****'),
                'metadata' => [
                    'brand' => $card->brand ?? null,
                    'last4' => $card->last4 ?? null,
                    'exp_month' => $card->exp_month ?? null,
                    'exp_year' => $card->exp_year ?? null,
                    'fingerprint' => $card->fingerprint ?? null,
                    'country' => $card->country ?? null,
                    'funding' => $card->funding ?? null,
                ],
            ];
        }

        // Save payment profile
        CompanyPaymentProfile::updateOrCreate(
            [
                'company_id' => $company->id,
                'provider_key' => 'stripe',
                'provider_payment_method_id' => $data['payment_method_id'],
            ],
            [
                'method_key' => $profileData['method_key'],
                'label' => $profileData['label'],
                'is_default' => true,
                'metadata' => $profileData['metadata'],
            ],
        );

        // Only activate if pending_payment (not trialing — trial stays as-is)
        if ($subscription->status === 'pending_payment') {
            $subscription->update([
                'status' => 'active',
                'is_current' => true,
                'started_at' => now(),
            ]);
        }

        // ADR-302: Charge any open addon invoices immediately
        $addonInvoicesPaid = 0;
        $openInvoices = Invoice::where('company_id', $company->id)
            ->where('status', 'open')
            ->get();

        foreach ($openInvoices as $invoice) {
            try {
                $result = $adapter->chargeInvoiceWithPaymentMethod(
                    $invoice,
                    $data['payment_method_id'],
                );

                if (($result['status'] ?? '') === 'succeeded') {
                    $invoice->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'provider_payment_id' => $result['provider_payment_id'] ?? null,
                    ]);
                    $addonInvoicesPaid++;
                }
            } catch (\Throwable $e) {
                Log::warning('[billing] Failed to charge addon invoice at registration', [
                    'invoice_id' => $invoice->id,
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[billing] Registration payment method confirmed', [
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'plan_key' => $subscription->plan_key,
            'method_type' => $type,
            'was_trial' => $subscription->status === 'trialing',
            'addon_invoices_paid' => $addonInvoicesPaid,
        ]);

        return response()->json([
            'message' => 'Payment method saved.',
            'subscription_status' => $subscription->status,
            'addon_invoices_paid' => $addonInvoicesPaid,
        ]);
    }
}
