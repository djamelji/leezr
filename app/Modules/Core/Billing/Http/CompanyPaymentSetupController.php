<?php

namespace App\Modules\Core\Billing\Http;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\BicRegistry;
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

        [$methodKey, $label, $profileMetadata, $fingerprint] = $this->extractProfileData($pm);

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
                    'card' => self::formatProfile($existing),
                    'duplicate' => true,
                ]);
            }
        }

        CompanyPaymentProfile::where('company_id', $company->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

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
                'metadata' => $profileMetadata,
            ],
        );

        return response()->json([
            'message' => 'Payment method saved.',
            'card' => self::formatProfile($profile),
        ]);
    }

    private function extractProfileData($pm): array
    {
        $type = $pm->type ?? 'card';

        if ($type === 'sepa_debit') {
            $sepa = $pm->sepa_debit;
            $holderName = $pm->billing_details?->name ?? null;

            return [
                'sepa_debit',
                'SEPA •••• '.($sepa?->last4 ?? '****'),
                ['type' => 'sepa_debit', 'bank_code' => $sepa?->bank_code, 'country' => $sepa?->country, 'last4' => $sepa?->last4 ?? '****', 'fingerprint' => $sepa?->fingerprint, 'holder_name' => $holderName],
                $sepa?->fingerprint,
            ];
        }

        $card = $pm->card ?? null;

        return [
            'card',
            ucfirst($card?->brand ?? 'unknown').' •••• '.($card?->last4 ?? '****'),
            ['brand' => $card?->brand ?? 'unknown', 'last4' => $card?->last4 ?? '****', 'exp_month' => $card?->exp_month, 'exp_year' => $card?->exp_year, 'fingerprint' => $card?->fingerprint, 'country' => $card?->country, 'funding' => $card?->funding],
            $card?->fingerprint,
        ];
    }

    public static function formatProfile(CompanyPaymentProfile $p): array
    {
        $bankCode = $p->metadata['bank_code'] ?? null;

        return [
            'id' => $p->id,
            'provider_payment_method_id' => $p->provider_payment_method_id,
            'label' => $p->label,
            'is_default' => $p->is_default,
            'method_key' => $p->method_key,
            'brand' => $p->metadata['brand'] ?? null,
            'last4' => $p->metadata['last4'] ?? null,
            'exp_month' => $p->metadata['exp_month'] ?? null,
            'exp_year' => $p->metadata['exp_year'] ?? null,
            'country' => $p->metadata['country'] ?? null,
            'funding' => $p->metadata['funding'] ?? null,
            'bank_code' => $bankCode,
            'bank_name' => $bankCode ? BicRegistry::resolve($bankCode) : null,
            'holder_name' => $p->metadata['holder_name'] ?? null,
        ];
    }
}
