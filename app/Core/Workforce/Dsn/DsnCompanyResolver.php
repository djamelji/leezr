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
        // ADR-784 — same legal identity as generated documents (ADR-783) : Company PROFILE first, legacy
        // columns only as fallback, nothing invented. Only fields with the SAME legal meaning are taken
        // from the profile (raison sociale, SIRET, postal address) ; NAF/APE, INSEE code and headcount
        // have no profile equivalent and stay on their columns.
        $id = \App\Company\Fields\ReadModels\CompanyLegalIdentity::for($company);
        $siret = $id['siret'] !== null ? str_replace(' ', '', $id['siret']) : null;

        return new DsnCompanyData(
            companyId: $company->id,
            name: $id['legal_name'],
            siret: $siret,
            siren: $siret ? substr($siret, 0, 9) : null,
            nic: $siret ? substr($siret, 9, 5) : null,
            nafCode: $company->naf_code,
            addressStreet: $id['address'],
            addressComplement: $id['complement'],
            addressPostalCode: $id['postal_code'],
            addressCity: $id['city'],
            addressInseeCode: $company->address_insee_code,
            addressCountryCode: $company->address_country_code ?? 'FR',
            averageHeadcount: $company->average_headcount,
            idcc: $company->defaultConventionCollective?->idcc,
        );
    }

    /**
     * ADR-784 — blocking pre-check of the employer identity (presence + existing format validators).
     *
     * @return array<string, string> field => 'missing' | 'invalid' (empty = ready)
     */
    public static function identityIssues(DsnCompanyData $data): array
    {
        $issues = array_fill_keys($data->missingMandatoryFields(), 'missing');
        if (! isset($issues['siret']) && ! self::validateSiret((string) $data->siret)) {
            $issues['siret'] = 'invalid';
        }
        if (! isset($issues['naf_code']) && ! self::validateNafCode((string) $data->nafCode)) {
            $issues['naf_code'] = 'invalid';
        }

        return $issues;
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
