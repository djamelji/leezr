<?php

namespace App\Core\Workforce\Dsn\Gateway\NetEntreprises;

use App\Platform\Models\PlatformSetting;

/**
 * Service for reading and validating Net-Entreprises DSN credentials.
 *
 * Credentials are stored encrypted in PlatformSetting.dsn JSON column.
 * This service provides a clean read interface and validation.
 *
 * Sprint 7.1 — ADR-529
 */
class DsnCredentialService
{
    /**
     * Required credential keys in PlatformSetting.dsn
     */
    private const REQUIRED_KEYS = [
        'ne_siret',
        'ne_nom',
        'ne_prenom',
        'ne_password',
    ];

    /**
     * Get all DSN credentials (decrypted).
     *
     * @return array{ne_siret: string, ne_nom: string, ne_prenom: string, ne_password: string, ne_environment: string}|null
     */
    public function getCredentials(): ?array
    {
        $settings = PlatformSetting::instance();
        $dsn = $settings->dsn;

        if (! is_array($dsn)) {
            return null;
        }

        return $dsn;
    }

    /**
     * Check if all required credentials are configured.
     */
    public function hasCredentials(): bool
    {
        $credentials = $this->getCredentials();

        if (! $credentials) {
            return false;
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (empty($credentials[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate credential formats without contacting Net-Entreprises.
     *
     * @return array<string, string> Map of field → error message (empty if valid)
     */
    public function validateFormats(): array
    {
        $credentials = $this->getCredentials();
        $errors = [];

        if (! $credentials) {
            $errors['dsn'] = 'DSN credentials not configured in PlatformSetting.';

            return $errors;
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (empty($credentials[$key])) {
                $errors[$key] = "Missing required credential: {$key}";
            }
        }

        // SIRET: 14 digits, Luhn checksum
        if (! empty($credentials['ne_siret'])) {
            $siret = $credentials['ne_siret'];
            if (! preg_match('/^\d{14}$/', $siret)) {
                $errors['ne_siret'] = 'SIRET must be exactly 14 digits.';
            } elseif (! $this->luhnCheck($siret)) {
                $errors['ne_siret'] = 'SIRET fails Luhn checksum.';
            }
        }

        // Environment: sandbox or production
        $env = $credentials['ne_environment'] ?? 'sandbox';
        if (! in_array($env, ['sandbox', 'production'], true)) {
            $errors['ne_environment'] = "Environment must be 'sandbox' or 'production', got: {$env}";
        }

        return $errors;
    }

    /**
     * Get the configured environment (sandbox/production).
     */
    public function getEnvironment(): string
    {
        $credentials = $this->getCredentials();

        return $credentials['ne_environment'] ?? config('workforce.dsn.ne_environment', 'sandbox');
    }

    /**
     * Luhn checksum verification for SIRET (14 digits).
     */
    private function luhnCheck(string $number): bool
    {
        $sum = 0;
        $length = strlen($number);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
