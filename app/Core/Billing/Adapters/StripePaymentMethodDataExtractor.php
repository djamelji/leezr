<?php

namespace App\Core\Billing\Adapters;

/**
 * ADR-362: Extract payment method data from Stripe PaymentMethod object.
 *
 * Maps Stripe PM response into CompanyPaymentProfile-compatible format.
 * Supports: card, sepa_debit.
 *
 * @return array{0: string, 1: string, 2: array, 3: string|null}
 *   [method_key, label, metadata, fingerprint]
 */
class StripePaymentMethodDataExtractor
{
    /**
     * Extract profile data from a Stripe PaymentMethod object.
     *
     * @return array{0: string, 1: string, 2: array, 3: string|null}
     */
    public static function extract($pm): array
    {
        $type = $pm->type ?? 'card';

        if ($type === 'sepa_debit') {
            return static::extractSepa($pm);
        }

        return static::extractCard($pm);
    }

    private static function extractSepa($pm): array
    {
        $sepa = $pm->sepa_debit;
        $holderName = $pm->billing_details?->name ?? null;

        return [
            'sepa_debit',
            'SEPA •••• ' . ($sepa?->last4 ?? '****'),
            [
                'type' => 'sepa_debit',
                'bank_code' => $sepa?->bank_code,
                'country' => $sepa?->country,
                'last4' => $sepa?->last4 ?? '****',
                'fingerprint' => $sepa?->fingerprint,
                'holder_name' => $holderName,
            ],
            $sepa?->fingerprint,
        ];
    }

    private static function extractCard($pm): array
    {
        $card = $pm->card ?? null;

        return [
            'card',
            ucfirst($card?->brand ?? 'unknown') . ' •••• ' . ($card?->last4 ?? '****'),
            [
                'brand' => $card?->brand ?? 'unknown',
                'last4' => $card?->last4 ?? '****',
                'exp_month' => $card?->exp_month,
                'exp_year' => $card?->exp_year,
                'fingerprint' => $card?->fingerprint,
                'country' => $card?->country,
                'funding' => $card?->funding,
            ],
            $card?->fingerprint,
        ];
    }
}
