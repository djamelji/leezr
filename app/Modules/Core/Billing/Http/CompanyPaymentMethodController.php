<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoicePayNowService;
use App\Platform\Models\PlatformSetting;
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

        return response()->json([
            'cards' => $cards,
            'max_payment_methods' => self::maxPaymentMethods(),
        ]);
    }

    public static function maxPaymentMethods(): int
    {
        $policies = (PlatformSetting::instance()->billing ?? [])['policies'] ?? [];

        return (int) ($policies['max_payment_methods'] ?? 4);
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

        // Guard: at least one payment method must remain
        $totalMethods = CompanyPaymentProfile::where('company_id', $company->id)->count();
        if ($totalMethods <= 1) {
            return response()->json([
                'message' => 'You must keep at least one payment method.',
            ], 422);
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

        if (! in_array($invoice->status, ['open', 'overdue'])) {
            return response()->json(['message' => 'Only open or overdue invoices can be paid.'], 422);
        }

        if ($invoice->amount_due <= 0) {
            return response()->json(['message' => 'Invoice has no amount due.'], 422);
        }

        // For overdue invoices: use DunningEngine (provider-first + wallet fallback)
        if ($invoice->status === 'overdue') {
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

        // For open invoices: use InvoicePayNowService (wallet + provider)
        try {
            $userId = $request->user()?->id;
            $idempotencyKey = "manual-pay-{$invoice->id}-" . now()->timestamp;

            $result = InvoicePayNowService::payNow($company, $idempotencyKey, $userId);

            if (in_array($invoice->id, $result['paid_invoice_ids'])) {
                return response()->json([
                    'result' => 'paid',
                    'message' => 'Payment successful.',
                ]);
            }

            return response()->json([
                'result' => 'partial',
                'message' => 'Wallet credit applied but insufficient to cover full amount.',
                'invoices_paid' => $result['invoices_paid'],
                'wallet_used' => $result['wallet_used'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
