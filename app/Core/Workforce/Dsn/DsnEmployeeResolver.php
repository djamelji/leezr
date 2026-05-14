<?php

namespace App\Core\Workforce\Dsn;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Workforce\Employee;
use Illuminate\Support\Collection;

/**
 * Resolves DSN identity data for employees.
 *
 * Reads raw (unmasked) field values directly from the Fields EAV system.
 * This bypasses FieldResolverService masking because DSN declarations
 * require complete, unmasked data (NIR, address, etc.).
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
     * @return DsnEmployeeData
     * @throws \DomainException if employee has no linked user
     */
    public static function resolve(Employee $employee): DsnEmployeeData
    {
        if (! $employee->user_id) {
            throw new \DomainException(
                "Employee #{$employee->id} ({$employee->employee_number}) has no linked user — cannot resolve DSN fields."
            );
        }

        $fieldValues = self::loadFieldValues([$employee->user_id]);
        $values = $fieldValues[$employee->user_id] ?? [];

        return self::buildData($employee, $values);
    }

    /**
     * Resolve DSN data for multiple employees (batch — avoids N+1).
     *
     * @param Collection|Employee[] $employees
     * @return array<int, DsnEmployeeData> keyed by employee_id
     */
    public static function resolveBatch(Collection $employees): array
    {
        $userIds = $employees->pluck('user_id')->filter()->unique()->values()->all();

        if (empty($userIds)) {
            return [];
        }

        $fieldValues = self::loadFieldValues($userIds);

        $result = [];
        foreach ($employees as $employee) {
            if (! $employee->user_id) {
                continue; // Skip employees without user — they'll fail validation later
            }
            $values = $fieldValues[$employee->user_id] ?? [];
            $result[$employee->id] = self::buildData($employee, $values);
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

    /**
     * Load raw field values for given user IDs.
     * Returns array keyed by user_id => [code => value].
     *
     * @param int[] $userIds
     * @return array<int, array<string, mixed>>
     */
    private static function loadFieldValues(array $userIds): array
    {
        // Get platform field definition IDs for DSN codes
        $definitionIds = FieldDefinition::whereNull('company_id')
            ->where('scope', FieldDefinition::SCOPE_COMPANY_USER)
            ->whereIn('code', self::DSN_FIELD_CODES)
            ->pluck('id', 'code'); // [code => id]

        if ($definitionIds->isEmpty()) {
            return [];
        }

        // Load all field values for these users+definitions in ONE query
        $values = FieldValue::where('model_type', 'App\\Core\\Models\\User')
            ->whereIn('model_id', $userIds)
            ->whereIn('field_definition_id', $definitionIds->values())
            ->get();

        // Reverse map: definition_id => code
        $idToCode = $definitionIds->flip();

        // Group by user_id => [code => value]
        $result = [];
        foreach ($values as $fv) {
            $code = $idToCode[$fv->field_definition_id] ?? null;
            if ($code) {
                // FieldValue.value is cast to array — extract scalar
                $rawValue = is_array($fv->value) ? ($fv->value['value'] ?? $fv->value[0] ?? null) : $fv->value;
                $result[$fv->model_id][$code] = $rawValue;
            }
        }

        return $result;
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
