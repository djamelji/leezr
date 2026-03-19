<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\Adapters\StripePaymentMethodDataExtractor;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\PlatformPaymentModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CompanyPaymentSetupController
{
    public function createSetupIntent(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        $module = PlatformPaymentModule::where('provider_key', 'stripe')->first();
        $publishableKey = $module?->getStripePublishableKey() ?? config('billing.stripe.key');
        $secretKey = $module?->getStripeSecretKey() ?? config('billing.stripe.secret');

        if (! $publishableKey || ! $secretKey) {
            Log::error('[billing] Stripe setup-intent configuration missing', [
                'company_id' => $company->id,
                'mode' => $module?->getStripeMode(),
                'publishable_key' => $publishableKey ? 'present' : 'missing',
                'secret_key' => $secretKey ? 'present' : 'missing',
            ]);

            return response()->json(['message' => 'Stripe is not configured correctly.'], 422);
        }

        if (! str_starts_with($secretKey, 'sk_test_') && ! str_starts_with($secretKey, 'sk_live_')) {
            Log::error('[billing] Stripe secret key has wrong format', [
                'company_id' => $company->id,
                'mode' => $module?->getStripeMode(),
                'key_prefix' => substr($secretKey, 0, 7),
            ]);

            return response()->json(['message' => 'Stripe is not configured correctly.'], 422);
        }

        $methodType = $request->input('method', 'card');

        if (! in_array($methodType, ['card', 'sepa_debit'])) {
            return response()->json(['message' => 'Invalid payment method type.'], 422);
        }

        try {
            $adapter = app(StripePaymentAdapter::class);
            $result = $adapter->createSetupIntent($company, $methodType);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            Log::error('[billing] Stripe SetupIntent invalid request', [
                'company_id' => $company->id,
                'method' => $methodType,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('[billing] SetupIntent fatal error', [
                'company_id' => $company->id,
                'method' => $methodType,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $userMessage = str_contains($e->getMessage(), 'Invalid API Key')
                ? 'Stripe is not configured correctly.'
                : 'Payment service temporarily unavailable.';

            return response()->json(['message' => $userMessage], 503);
        }

        return response()->json([
            'client_secret' => $result['client_secret'],
            'publishable_key' => $publishableKey,
        ]);
    }

    public function confirmSetupIntent(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        $paymentMethodId = $request->validate(['payment_method_id' => 'required|string'])['payment_method_id'];

        // Guard: max payment methods per company (platform policy)
        $max = CompanyPaymentMethodController::maxPaymentMethods();
        $count = CompanyPaymentProfile::where('company_id', $company->id)->count();
        if ($count >= $max) {
            return response()->json([
                'message' => "Maximum of {$max} payment methods allowed.",
            ], 422);
        }

        try {
            $adapter = app(StripePaymentAdapter::class);
            $pm = $adapter->retrievePaymentMethod($paymentMethodId);
        } catch (\Throwable $e) {
            Log::error('[billing] Confirm setup-intent: failed to retrieve PM', [
                'company_id' => $company->id,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not verify payment method.'], 422);
        }

        [$methodKey, $label, $profileMetadata, $fingerprint] = StripePaymentMethodDataExtractor::extract($pm);

        // Deduplicate by fingerprint
        if ($fingerprint) {
            $existing = CompanyPaymentProfile::where('company_id', $company->id)
                ->where('provider_key', 'stripe')
                ->get()
                ->first(fn ($p) => ($p->metadata['fingerprint'] ?? null) === $fingerprint);

            if ($existing) {
                try { $adapter->detachPaymentMethod($paymentMethodId); } catch (\Throwable) {}

                return response()->json([
                    'message' => 'Payment method already saved.',
                    'card' => $existing->toCardArray(),
                    'duplicate' => true,
                ]);
            }
        }

        CompanyPaymentProfile::where('company_id', $company->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // ADR-328 S4: Store SEPA mandate acceptance metadata
        if ($methodKey === 'sepa_debit') {
            $profileMetadata['mandate_accepted_at'] = now()->toIso8601String();
            $profileMetadata['mandate_reference'] = 'MNDT-' . $company->id . '-' . \Illuminate\Support\Str::random(8);
        }

        // Merge with existing metadata to preserve mandate/debit-day data across updates
        $existing = CompanyPaymentProfile::where('company_id', $company->id)
            ->where('provider_key', 'stripe')
            ->where('provider_payment_method_id', $paymentMethodId)
            ->first();

        $mergedMetadata = array_merge($existing?->metadata ?? [], $profileMetadata);

        $profile = CompanyPaymentProfile::updateOrCreate(
            [
                'company_id' => $company->id,
                'provider_key' => 'stripe',
                'provider_payment_method_id' => $paymentMethodId,
            ],
            [
                'method_key' => $methodKey,
                'label' => $label,
                'is_default' => true,
                'metadata' => $mergedMetadata,
            ],
        );

        return response()->json([
            'message' => 'Payment method saved.',
            'card' => $profile->toCardArray(),
        ]);
    }

}
