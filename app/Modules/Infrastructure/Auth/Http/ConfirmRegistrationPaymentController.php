<?php

namespace App\Modules\Infrastructure\Auth\Http;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BillingCoupon;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\InvoiceLineDescriptor;
use App\Core\Billing\Subscription;
use App\Core\Plans\Plan;
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

        // P0-B (ADR-734): the subscription must NOT become `active` on a saved card
        // alone. We prepare the plan invoice, attempt the charge, and only activate
        // once the plan term is really collected — otherwise we stay pending_payment
        // with an OPEN (unpaid) invoice, so there is never a paid-looking state
        // without a proven encaissement.
        $isPendingActivation = $subscription->status === 'pending_payment';
        $planInvoice = null;
        $periodStart = $subscription->current_period_start ?? now();
        $periodEnd = $subscription->current_period_end
            ?? ($subscription->interval === 'yearly' ? now()->addYear() : now()->addMonth());

        if ($isPendingActivation) {
            // Reuse an already-open plan invoice (retry after a failed charge) instead
            // of creating a duplicate; otherwise issue it now.
            $planInvoice = Invoice::where('company_id', $company->id)
                ->where('subscription_id', $subscription->id)
                ->where('status', 'open')
                ->first();

            if (! $planInvoice) {
                $plan = Plan::where('key', $subscription->plan_key)->first();

                if ($plan) {
                    $price = $subscription->interval === 'yearly'
                        ? $plan->price_yearly
                        : $plan->price_monthly;

                    if ($price > 0) {
                        $invoice = InvoiceIssuer::createDraft(
                            $company,
                            $subscription->id,
                            $periodStart->toDateString(),
                            $periodEnd->toDateString(),
                        );

                        $desc = InvoiceLineDescriptor::resolve($company->market?->locale ?? 'fr-FR');

                        InvoiceIssuer::addLine($invoice, 'plan', $desc->plan($plan->name), $price, 1);

                        // Apply coupon if attached to subscription
                        if ($subscription->coupon_id) {
                            $coupon = BillingCoupon::find($subscription->coupon_id);

                            if ($coupon) {
                                InvoiceIssuer::applyCoupon($invoice, $coupon, $company);
                            }
                        }

                        InvoiceIssuer::finalize($invoice);
                        $planInvoice = $invoice->fresh();
                    }
                }
            }
        }

        // Charge every open invoice (plan + addons). Track whether the PLAN term settled.
        // Nothing to collect (free / fully-couponed → no open plan invoice, or already
        // paid) counts as settled.
        $planSettled = ! $isPendingActivation
            || $planInvoice === null
            || $planInvoice->status === 'paid';
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

                    if ($planInvoice && $invoice->id === $planInvoice->id) {
                        $planSettled = true;
                    } else {
                        $addonInvoicesPaid++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[billing] Failed to charge invoice at registration', [
                    'invoice_id' => $invoice->id,
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Activate ONLY after the plan term is really collected (ADR-734).
        if ($isPendingActivation && $planSettled) {
            $subscription->update([
                'status' => 'active',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);
        } elseif ($isPendingActivation) {
            Log::warning('[billing] Registration activation deferred — plan charge not settled', [
                'company_id' => $company->id,
                'subscription_id' => $subscription->id,
                'plan_invoice_id' => $planInvoice?->id,
            ]);
        }

        $subscription->refresh();
        $planPaid = $isPendingActivation ? $planSettled : null;

        Log::info('[billing] Registration payment method confirmed', [
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'plan_key' => $subscription->plan_key,
            'method_type' => $type,
            'subscription_status' => $subscription->status,
            'plan_settled' => $planPaid,
            'addon_invoices_paid' => $addonInvoicesPaid,
        ]);

        return response()->json([
            'message' => $isPendingActivation && ! $planSettled
                ? 'Payment could not be collected. Please try another payment method.'
                : 'Payment method saved.',
            'subscription_status' => $subscription->status,
            'plan_settled' => $planPaid,
            'addon_invoices_paid' => $addonInvoicesPaid,
        ], $isPendingActivation && ! $planSettled ? 402 : 200);
    }
}
