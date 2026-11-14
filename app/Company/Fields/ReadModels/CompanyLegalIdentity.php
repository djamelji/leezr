<?php

namespace App\Company\Fields\ReadModels;

use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Models\Company;

/**
 * ADR-783 (UX6.2) — ONE reading of a company's legal identity for generated documents and screens.
 *
 * Canonical source = the Company PROFILE (dynamic fields edited in Réglages › Profil de l'entreprise),
 * the same source invoices already use (InvoiceIssuer). The legacy `companies.address_*` / `siret` /
 * `naf_code` columns are read ONLY as a fallback when the profile value is empty (historical data) :
 * nothing is invented, an absent value stays empty. Read-only : no write, no migration.
 */
final class CompanyLegalIdentity
{
    /** Profile field codes read by this model. */
    private const FIELDS = ['legal_name', 'legal_form', 'siret', 'vat_number', 'company_address', 'company_complement', 'company_postal_code', 'company_city'];

    /** Items a contract cannot do without (header / employer clause). */
    public const REQUIRED_FOR_CONTRACT = ['address', 'postal_code', 'city'];

    /**
     * @return array{name: string, legal_name: string, legal_form: ?string, siret: ?string, naf_code: ?string,
     *               vat_number: ?string, address: ?string, complement: ?string, postal_code: ?string,
     *               city: ?string, country: ?string, country_code: ?string}
     */
    public static function for(Company $company): array
    {
        $values = FieldValue::where('model_type', 'company')
            ->where('model_id', $company->id)
            ->whereHas('definition', fn ($q) => $q->whereIn('code', self::FIELDS))
            ->with('definition:id,code')
            ->get()
            ->pluck('value', 'definition.code')
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->all();

        $pick = fn (string $code, $legacy = null) => self::filled($values[$code] ?? null) ? $values[$code] : (self::filled($legacy) ? trim((string) $legacy) : null);
        $countryCode = $company->address_country_code ?: $company->market_key;

        return [
            'name' => (string) $company->name,
            'legal_name' => $pick('legal_name') ?? (string) $company->name,
            'legal_form' => self::filled($company->legalStatus?->name ?? null) ? $company->legalStatus->name : $pick('legal_form'),
            'siret' => $pick('siret', $company->siret),
            'naf_code' => self::filled($company->naf_code) ? $company->naf_code : null,
            'vat_number' => $pick('vat_number'),
            'address' => $pick('company_address', $company->address_street),
            'complement' => $pick('company_complement', $company->address_complement),
            'postal_code' => $pick('company_postal_code', $company->address_postal_code),
            'city' => $pick('company_city', $company->address_city),
            'country' => $countryCode ? (Market::where('key', $countryCode)->value('name') ?? $countryCode) : null,
            'country_code' => $countryCode,
        ];
    }

    /** Lines usable in a document ; empty parts are dropped, never replaced by a placeholder. */
    public static function documentLines(array $identity): array
    {
        $street = trim(implode(' ', array_filter([$identity['address'], $identity['complement']])));
        $city = trim(implode(' ', array_filter([$identity['postal_code'], $identity['city']])));

        return [
            'street' => $street,
            'city' => $city,
            'full' => trim(implode(', ', array_filter([$street, $city, $identity['country']]))),
        ];
    }

    /** @return string[] missing keys among REQUIRED_FOR_CONTRACT */
    public static function missingForContract(array $identity): array
    {
        return array_values(array_filter(self::REQUIRED_FOR_CONTRACT, fn ($k) => ! self::filled($identity[$k] ?? null)));
    }

    private static function filled($v): bool
    {
        return $v !== null && trim((string) $v) !== '';
    }

    /** ADR-784 — actionable refusal body when a contract cannot be issued for this company, else null. */
    public static function contractRefusal(int $companyId): ?array
    {
        $missing = self::missingForContract(self::for(Company::findOrFail($companyId)));

        return $missing ? ['message' => __('contracts.employer_identity_incomplete'), 'code' => 'employer_identity_incomplete', 'missing' => $missing, 'fix' => ['name' => 'company-profile-tab', 'params' => ['tab' => 'overview']]] : null;
    }
}
