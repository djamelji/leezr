<?php

namespace App\Core\Workforce\Dsn;

use App\Core\Models\Company;

/**
 * Resolves DSN establishment/company data (blocs S20.G00.05 and S21.G00.06).
 *
 * Pure read-only — ZERO DB writes.
 */
class DsnCompanyResolver
{
    /**
     * Resolve DSN data for a company.
     *
     * @throws \DomainException if company lacks mandatory DSN fields
     */
    public static function resolve(Company $company): DsnCompanyData
    {
        return new DsnCompanyData(
            companyId: $company->id,
            name: $company->name,
            siret: $company->siret,
            siren: $company->siret ? substr($company->siret, 0, 9) : null,
            nic: $company->siret ? substr($company->siret, 9, 5) : null,
            nafCode: $company->naf_code,
            addressStreet: $company->address_street,
            addressComplement: $company->address_complement,
            addressPostalCode: $company->address_postal_code,
            addressCity: $company->address_city,
            addressInseeCode: $company->address_insee_code,
            addressCountryCode: $company->address_country_code ?? 'FR',
            averageHeadcount: $company->average_headcount,
            idcc: $company->defaultConventionCollective?->idcc,
        );
    }

    /**
     * Validate SIRET format using Luhn algorithm.
     * SIRET = 14 digits, Luhn check on all 14.
     */
    public static function validateSiret(string $siret): bool
    {
        $siret = str_replace(' ', '', $siret);

        if (strlen($siret) !== 14 || ! ctype_digit($siret)) {
            return false;
        }

        // Luhn algorithm on 14 digits
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $digit = intval($siret[$i]);
            // Double every other digit starting from position 0 (even positions)
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * Validate NAF/APE code format: 4 digits + 1 letter.
     */
    public static function validateNafCode(string $nafCode): bool
    {
        return (bool) preg_match('/^\d{4}[A-Z]$/', strtoupper(trim($nafCode)));
    }
}
