<?php

namespace App\Modules\Core\Billing\Services;

use App\Core\Billing\VatCheck;
use Illuminate\Support\Facades\Log;

/**
 * EU VAT number validation via VIES SOAP service (ADR-310).
 *
 * Results are cached in billing_vat_checks for 7 days.
 * Graceful fallback: if VIES is unavailable, the VAT number is assumed valid.
 */
class VatValidationService
{
    private const VIES_WSDL = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';

    private const CACHE_DAYS = 7;

    /**
     * Override for testing: callable(string $vatNumber, string $countryCode): object|null
     * When set, replaces the real SOAP call. Return null to simulate VIES unavailable.
     */
    public static ?\Closure $testSoapOverride = null;

    /**
     * Validate a VAT number against VIES.
     *
     * @return array{valid: bool, name: ?string, address: ?string, cached: bool}
     */
    public static function validate(string $vatNumber, string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);
        $vatNumber = preg_replace('/\s+/', '', $vatNumber);

        // Strip country prefix if present (e.g. "DE123456789" → "123456789")
        if (str_starts_with(strtoupper($vatNumber), $countryCode)) {
            $vatNumber = substr($vatNumber, strlen($countryCode));
        }

        // Check cache first
        $cached = VatCheck::where('vat_number', $vatNumber)
            ->where('country_code', $countryCode)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            return [
                'valid' => $cached->is_valid,
                'name' => $cached->name,
                'address' => $cached->address,
                'cached' => true,
            ];
        }

        // Call VIES SOAP
        return static::callVies($vatNumber, $countryCode);
    }

    /**
     * Call the VIES SOAP service and cache the result.
     */
    private static function callVies(string $vatNumber, string $countryCode): array
    {
        try {
            if (static::$testSoapOverride !== null) {
                $response = (static::$testSoapOverride)($vatNumber, $countryCode);
                if ($response === null) {
                    throw new \RuntimeException('VIES unavailable (test override)');
                }
            } else {
                $client = new \SoapClient(self::VIES_WSDL, [
                    'connection_timeout' => 10,
                    'exceptions' => true,
                ]);

                $response = $client->checkVat([
                    'countryCode' => $countryCode,
                    'vatNumber' => $vatNumber,
                ]);
            }

            $result = [
                'valid' => (bool) $response->valid,
                'name' => $response->name !== '---' ? $response->name : null,
                'address' => $response->address !== '---' ? $response->address : null,
                'cached' => false,
            ];

            // Store in cache
            VatCheck::updateOrCreate(
                ['vat_number' => $vatNumber, 'country_code' => $countryCode],
                [
                    'is_valid' => $result['valid'],
                    'name' => $result['name'],
                    'address' => $result['address'],
                    'checked_at' => now(),
                    'expires_at' => now()->addDays(self::CACHE_DAYS),
                ],
            );

            return $result;
        } catch (\Throwable $e) {
            Log::warning('[vat] VIES validation unavailable, assuming valid', [
                'country_code' => $countryCode,
                'vat_number' => $vatNumber,
                'error' => $e->getMessage(),
            ]);

            // Graceful fallback: assume valid when VIES is down
            return [
                'valid' => true,
                'name' => null,
                'address' => null,
                'cached' => false,
            ];
        }
    }
}
