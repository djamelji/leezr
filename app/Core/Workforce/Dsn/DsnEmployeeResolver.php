<?php

namespace App\Core\Workforce\Dsn;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Workforce\Employee;
use Illuminate\Support\Collection;

/**
 * Resolves DSN identity data for employees.
 *
 * Reads raw (unmasked) canonical values through EmployeeFieldStore::rawValues (ADR-795.1) — internal
 * producer, never an API : DSN declarations require complete, unmasked data (NIR, address, etc.).
 *
 * Pure read-only — ZERO DB writes.
 */
class DsnEmployeeResolver
{
    /** Field codes needed for DSN S21.G00.30 bloc */
    private const DSN_FIELD_CODES = [
        'social_security_number',
        'gender',
        'birth_date',
        'birth_city',
        'birth_department',
        'birth_country',
        'nationality',
        'personal_address_street',
        'personal_address_postal_code',
        'personal_address_city',
        'personal_address_country_code',
    ];

    /**
     * Resolve DSN data for a single employee.
     *
     * ADR-795.1 — values come from the CANONICAL resolution of the employee's company
     * (EmployeeFieldStore::rawValues) : HR record for an employee WITHOUT account, account values only if
     * disclosed to this company. A missing value stays missing (DsnEmployeeData::missingMandatoryFields
     * then blocks explicitly) — nothing is invented, the DSN stays a simulation.
     */
    public static function resolve(Employee $employee): DsnEmployeeData
    {
        return self::resolveBatch(collect([$employee]))[$employee->id];
    }

    /**
     * Resolve DSN data for multiple employees (batch — constant queries per company).
     *
     * @param Collection|Employee[] $employees
     * @return array<int, DsnEmployeeData> keyed by employee_id
     */
    public static function resolveBatch(Collection $employees): array
    {
        $result = [];
        foreach ($employees->groupBy('company_id') as $companyId => $group) {
            $company = \App\Core\Models\Company::find($companyId);
            if (! $company) {
                continue;
            }
            $values = (new \App\Core\Fields\EmployeeFieldStore($company))->rawValues($group, self::DSN_FIELD_CODES);
            foreach ($group as $employee) {
                $result[$employee->id] = self::buildData($employee, array_map(
                    fn ($v) => is_array($v) ? ($v['value'] ?? $v[0] ?? null) : $v, // FieldValue.value est casté en tableau
                    $values[$employee->id] ?? [],
                ));
            }
        }

        return $result;
    }

    /**
     * Validate NIR format: 13 digits + 2-digit check key.
     * Handles Corsica: 2A→19, 2B→18 before modulo.
     *
     * @return bool true if NIR is valid
     */
    public static function validateNir(string $nir): bool
    {
        // Remove spaces
        $nir = str_replace(' ', '', $nir);

        // Must be exactly 15 characters
        if (strlen($nir) !== 15) {
            return false;
        }

        // Extract 13-digit base and 2-digit key
        $base = substr($nir, 0, 13);
        $key = substr($nir, 13, 2);

        if (! ctype_digit($key)) {
            return false;
        }

        // Handle Corsica (2A, 2B in positions 6-7)
        $baseForCalc = $base;
        if (str_contains($baseForCalc, '2A')) {
            $baseForCalc = str_replace('2A', '19', $baseForCalc);
        } elseif (str_contains($baseForCalc, '2B')) {
            $baseForCalc = str_replace('2B', '18', $baseForCalc);
        }

        // After Corsica substitution, must be all digits
        if (! ctype_digit($baseForCalc)) {
            return false;
        }

        // Modulo 97 check: key = 97 - (base % 97)
        $expectedKey = 97 - (intval($baseForCalc) % 97);

        return intval($key) === $expectedKey;
    }

    private static function buildData(Employee $employee, array $fieldValues): DsnEmployeeData
    {
        return new DsnEmployeeData(
            employeeId: $employee->id,
            employeeNumber: $employee->employee_number,
            firstName: $employee->first_name,
            lastName: $employee->last_name,
            email: $employee->email,
            hireDate: $employee->hire_date?->format('Y-m-d'),
            nir: $fieldValues['social_security_number'] ?? null,
            gender: $fieldValues['gender'] ?? null,
            birthDate: $fieldValues['birth_date'] ?? null,
            birthCity: $fieldValues['birth_city'] ?? null,
            birthDepartment: $fieldValues['birth_department'] ?? null,
            birthCountry: $fieldValues['birth_country'] ?? null,
            nationality: $fieldValues['nationality'] ?? null,
            addressStreet: $fieldValues['personal_address_street'] ?? null,
            addressPostalCode: $fieldValues['personal_address_postal_code'] ?? null,
            addressCity: $fieldValues['personal_address_city'] ?? null,
            addressCountryCode: $fieldValues['personal_address_country_code'] ?? null,
        );
    }
}
