<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BillingCheckoutSession;
use App\Core\Billing\CheckoutSessionActivator;
use App\Core\Billing\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-229: Checkout status polling endpoint (leg 2 of triple recovery).
 *
 * Called by the success page after Stripe redirect to verify and activate.
 */
class CheckoutStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');
        $sessionId = $request->query('session_id');

        // Look up local checkout session
        $localSession = BillingCheckoutSession::where('provider_session_id', $sessionId)
            ->first();

        if (! $localSession) {
            return response()->json(['message' => 'Checkout session not found.'], 404);
        }

        // Security: session must belong to this company
        if ($localSession->company_id !== $company->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Already completed locally
        if ($localSession->isCompleted()) {
            $subscription = Subscription::find($localSession->subscription_id);

            return response()->json([
                'status' => 'completed',
                'subscription_status' => $subscription?->status,
                'company_plan_key' => $company->fresh()->plan_key,
            ]);
        }

        // Poll Stripe for the session status
        try {
            $adapter = app(StripePaymentAdapter::class);
            $stripeSession = $adapter->retrieveCheckoutSession($sessionId);
            $stripeData = $stripeSession->toArray();

            $localSession->update(['last_checked_at' => now()]);

            $stripeStatus = $stripeData['status'] ?? null;
            $paymentStatus = $stripeData['payment_status'] ?? null;

            // Session completed + paid → activate
            if ($stripeStatus === 'complete' && $paymentStatus === 'paid') {
                $result = CheckoutSessionActivator::activateFromStripeSession($stripeData);

                $subscription = Subscription::find($localSession->subscription_id);

                return response()->json([
                    'status' => 'completed',
                    'activated' => $result->activated,
                    'subscription_status' => $subscription?->fresh()?->status,
                    'company_plan_key' => $company->fresh()->plan_key,
                ]);
            }

            // Map Stripe status to local status
            if (in_array($stripeStatus, ['expired'])) {
                $localSession->update(['status' => 'expired']);
            }

            return response()->json([
                'status' => $stripeStatus ?? 'created',
                'payment_status' => $paymentStatus,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => $localSession->status,
                'error' => 'Unable to check with payment provider.',
            ]);
        }
    }
}
