<?php

namespace App\Core\Workforce\Dsn;

/**
 * DSN identity data for a single employee (S21.G00.30).
 * Pure DTO — readonly, no DB access.
 */
readonly class DsnEmployeeData
{
    public function __construct(
        public int $employeeId,
        public ?string $employeeNumber,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $hireDate,
        public ?string $nir,
        public ?string $gender,
        public ?string $birthDate,
        public ?string $birthCity,
        public ?string $birthDepartment,
        public ?string $birthCountry,
        public ?string $nationality,
        public ?string $addressStreet,
        public ?string $addressPostalCode,
        public ?string $addressCity,
        public ?string $addressCountryCode,
    ) {}

    /**
     * Check if this employee has all mandatory DSN fields.
     * @return string[] list of missing field names
     */
    public function missingMandatoryFields(): array
    {
        $missing = [];
        if (empty($this->nir)) $missing[] = 'nir';
        if (empty($this->gender)) $missing[] = 'gender';
        if (empty($this->birthDate)) $missing[] = 'birth_date';
        if (empty($this->birthCountry)) $missing[] = 'birth_country';
        if (empty($this->lastName)) $missing[] = 'last_name';
        if (empty($this->firstName)) $missing[] = 'first_name';
        if (empty($this->addressStreet)) $missing[] = 'personal_address_street';
        if (empty($this->addressPostalCode)) $missing[] = 'personal_address_postal_code';
        if (empty($this->addressCity)) $missing[] = 'personal_address_city';
        return $missing;
    }

    /**
     * Is this employee DSN-ready (all mandatory fields present)?
     */
    public function isDsnReady(): bool
    {
        return empty($this->missingMandatoryFields());
    }

    /**
     * DSN gender code: M→01, F→02
     */
    public function dsnGenderCode(): ?string
    {
        return match ($this->gender) {
            'M' => '01',
            'F' => '02',
            default => null,
        };
    }
}
