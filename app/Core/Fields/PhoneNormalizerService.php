<?php

namespace App\Core\Fields;

/**
 * ADR-165: Normalize phone numbers to E.164 format.
 *
 * Uses the company's market dial_code to prefix local numbers.
 */
class PhoneNormalizerService
{
    /**
     * Normalize a raw phone string to E.164 format.
     *
     * - Already E.164 (+digits): returned as-is
     * - Local format (0612...): strip leading 0, prepend dial_code
     * - Non-digit characters (spaces, dashes, dots) are stripped
     */
    public static function normalize(string $raw, string $dialCode = '+33'): string
    {
        // Strip everything except digits and leading +
        $digits = preg_replace('/[^\d+]/', '', $raw);

        if ($digits === '' || $digits === '+') {
            return $raw; // Not a parseable phone number — return as-is
        }

        // Already E.164 (starts with +)
        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        // Strip leading 0 (local format)
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $dialCode . $digits;
    }
}
