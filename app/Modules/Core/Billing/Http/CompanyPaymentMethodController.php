<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyPaymentMethodController
{
    public function savedCards(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $profiles = CompanyPaymentProfile::where('company_id', $company->id)->get();
        $cards = $profiles->map(fn ($p) => CompanyPaymentSetupController::formatProfile($p));

        return response()->json(['cards' => $cards]);
    }

    public function deleteCard(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $profile = CompanyPaymentProfile::where('id', $id)
            ->where('company_id', $company->id)
            ->first();

        if (! $profile) {
            return response()->json(['message' => 'Card not found.'], 404);
        }

        // Stripe detach best-effort
        if ($profile->provider_payment_method_id) {
            try {
                $adapter = app(StripePaymentAdapter::class);
                $adapter->detachPaymentMethod($profile->provider_payment_method_id);
            } catch (\Throwable $e) {
                Log::warning('[billing] Stripe detach failed', [
                    'profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $wasDefault = $profile->is_default;
        $profile->delete();

        // Promote next card if deleted was default
        if ($wasDefault) {
            CompanyPaymentProfile::where('company_id', $company->id)
                ->orderBy('id')
                ->first()
                ?->update(['is_default' => true]);
        }

        return response()->json(['message' => 'Card removed.']);
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $profile = CompanyPaymentProfile::where('id', $id)
            ->where('company_id', $company->id)
            ->first();

        if (! $profile) {
            return response()->json(['message' => 'Card not found.'], 404);
        }

        // Unset old defaults
        CompanyPaymentProfile::where('company_id', $company->id)
            ->where('id', '!=', $profile->id)
            ->update(['is_default' => false]);

        $profile->update(['is_default' => true]);

        // Stripe set default best-effort
        try {
            $customer = CompanyPaymentCustomer::where('company_id', $company->id)
                ->where('provider_key', 'stripe')
                ->first();

            if ($customer && $profile->provider_payment_method_id) {
                $adapter = app(StripePaymentAdapter::class);
                $adapter->setDefaultPaymentMethod(
                    $customer->provider_customer_id,
                    $profile->provider_payment_method_id,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[billing] Stripe set default PM failed', [
                'profile_id' => $profile->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Default card updated.']);
    }

    public function retryInvoice(Request $request, int $id): JsonResponse
    {
        $company = $request->attributes->get('company');

        $invoice = Invoice::where('id', $id)
            ->where('company_id', $company->id)
            ->first();

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status !== 'overdue') {
            return response()->json(['message' => 'Only overdue invoices can be retried.'], 422);
        }

        $result = DunningEngine::retrySingleInvoice($invoice);

        $messages = [
            'paid' => 'Payment successful.',
            'provider_attempted' => 'Payment submitted. Processing may take a moment.',
            'retried' => 'Payment scheduled for retry.',
            'exhausted' => 'All retry attempts exhausted.',
            'skipped' => 'Invoice already processed.',
        ];

        return response()->json([
            'result' => $result,
            'message' => $messages[$result] ?? 'Retry completed.',
        ]);
    }
}
