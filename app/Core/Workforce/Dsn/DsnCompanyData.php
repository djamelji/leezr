<?php

namespace App\Core\Workforce\Dsn;

/**
 * DSN establishment data (blocs S20.G00.05 + S21.G00.06).
 * Pure DTO — readonly, no DB access.
 */
readonly class DsnCompanyData
{
    public function __construct(
        public int $companyId,
        public string $name,
        public ?string $siret,
        public ?string $siren,
        public ?string $nic,
        public ?string $nafCode,
        public ?string $addressStreet,
        public ?string $addressComplement,
        public ?string $addressPostalCode,
        public ?string $addressCity,
        public ?string $addressInseeCode,
        public ?string $addressCountryCode,
        public ?int $averageHeadcount,
        public ?string $idcc,
    ) {}

    /**
     * Check if company has all mandatory DSN fields.
     * @return string[] list of missing field names
     */
    public function missingMandatoryFields(): array
    {
        $missing = [];
        if (empty($this->siret)) $missing[] = 'siret';
        if (empty($this->nafCode)) $missing[] = 'naf_code';
        if (empty($this->addressStreet)) $missing[] = 'address_street';
        if (empty($this->addressPostalCode)) $missing[] = 'address_postal_code';
        if (empty($this->addressCity)) $missing[] = 'address_city';
        return $missing;
    }

    /**
     * Is this company DSN-ready (all mandatory fields present)?
     */
    public function isDsnReady(): bool
    {
        return empty($this->missingMandatoryFields());
    }
}
