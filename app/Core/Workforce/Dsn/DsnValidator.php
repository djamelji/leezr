<?php

namespace App\Core\Workforce\Dsn;

/**
 * Validates a DsnPayload before serialization.
 *
 * Pure static service — ZERO DB access, ZERO side effects.
 *
 * 5 validation categories:
 *   1. siret     — SIRET Luhn checksum (14 digits)
 *   2. nir       — NIR modulo 97 check (per employee)
 *   3. ctp       — All contribution codes must have CTP mapping
 *   4. totals    — Sum of individual lines = declared aggregates
 *   5. encoding  — All string values must be ISO-8859-1 compatible
 *
 * Sprint 6.3 — ADR-521, Sprint 6.6 — ADR-524 (enriched with severity/rubrique/context)
 */
class DsnValidator
{
    /**
     * Validate a DsnPayload. Returns structured result.
     */
    public static function validate(DsnPayload $payload): DsnValidationResult
    {
        $entries = [];

        $entries = array_merge($entries, self::validateSiret($payload->company));
        $entries = array_merge($entries, self::validateEmployees($payload->employees));
        $entries = array_merge($entries, self::validateCtpMappings($payload->employees));
        $entries = array_merge($entries, self::validateTotals($payload));
        $entries = array_merge($entries, self::validateEncoding($payload));

        if (empty($entries)) {
            return DsnValidationResult::ok();
        }

        return DsnValidationResult::fromEntries($entries);
    }

    // ── 1. SIRET validation ──

    private static function validateSiret(DsnCompanyData $company): array
    {
        $entries = [];

        if (empty($company->siret)) {
            $entries[] = [
                'severity' => DsnValidationResult::SEVERITY_ERROR,
                'category' => 'siret',
                'rubrique' => 'S21.G00.06.001',
                'field' => 'company.siret',
                'message' => 'SIRET is required for DSN declaration',
            ];

            return $entries;
        }

        if (! DsnCompanyResolver::validateSiret($company->siret)) {
            $entries[] = [
                'severity' => DsnValidationResult::SEVERITY_ERROR,
                'category' => 'siret',
                'rubrique' => 'S21.G00.06.001',
                'field' => 'company.siret',
                'message' => "SIRET '{$company->siret}' fails Luhn checksum validation",
            ];
        }

        return $entries;
    }

    // ── 2. NIR + employee mandatory fields ──

    private static function validateEmployees(array $employees): array
    {
        $entries = [];

        foreach ($employees as $block) {
            /** @var DsnEmployeeBlock $block */
            $employee = $block->identity;

            // Missing mandatory fields
            $missing = $employee->missingMandatoryFields();
            foreach ($missing as $field) {
                $entries[] = [
                    'severity' => DsnValidationResult::SEVERITY_ERROR,
                    'category' => 'nir',
                    'rubrique' => self::fieldToRubrique($field),
                    'field' => "employee.{$field}",
                    'message' => "Employee {$employee->lastName} {$employee->firstName}: missing mandatory field '{$field}'",
                    'employee_id' => $employee->employeeId,
                    'payroll_line_id' => $block->payrollLineId,
                ];
            }

            // NIR modulo 97
            if (! empty($employee->nir)) {
                if (! DsnEmployeeResolver::validateNir($employee->nir)) {
                    $entries[] = [
                        'severity' => DsnValidationResult::SEVERITY_ERROR,
                        'category' => 'nir',
                        'rubrique' => 'S21.G00.30.001',
                        'field' => 'employee.nir',
                        'message' => "Employee {$employee->lastName} {$employee->firstName}: NIR '{$employee->nir}' fails modulo-97 check",
                        'employee_id' => $employee->employeeId,
                        'payroll_line_id' => $block->payrollLineId,
                    ];
                }
            }
        }

        return $entries;
    }

    // ── 3. CTP mapping validation ──

    private static function validateCtpMappings(array $employees): array
    {
        $entries = [];
        $unmappedCodes = [];

        foreach ($employees as $block) {
            /** @var DsnEmployeeBlock $block */
            foreach ($block->contributionLines as $line) {
                $code = $line['code'] ?? '';
                if (empty($code)) {
                    continue;
                }

                // Skip CSG/CRDS — they don't need CTP
                if (DsnCtpMapping::isCsgCrds($code)) {
                    continue;
                }

                if (DsnCtpMapping::get($code) === null && ! in_array($code, $unmappedCodes, true)) {
                    $unmappedCodes[] = $code;
                    $entries[] = [
                        'severity' => DsnValidationResult::SEVERITY_ERROR,
                        'category' => 'ctp',
                        'rubrique' => 'S21.G00.81.001',
                        'field' => 'contribution.code',
                        'message' => "Contribution code '{$code}' has no CTP mapping — cannot include in DSN",
                        'employee_id' => $block->identity->employeeId,
                        'payroll_line_id' => $block->payrollLineId,
                    ];
                }
            }
        }

        return $entries;
    }

    // ── 4. Totals coherence ──

    private static function validateTotals(DsnPayload $payload): array
    {
        $entries = [];

        // Build expected CTP aggregates from individual employee contribution lines
        $expectedByCtp = [];

        foreach ($payload->employees as $block) {
            /** @var DsnEmployeeBlock $block */
            foreach ($block->contributionLines as $line) {
                $code = $line['code'] ?? '';
                $ctpCode = DsnCtpMapping::ctpCode($code);
                if ($ctpCode === null) {
                    continue; // CSG/CRDS or unmapped — skip CTP aggregation
                }

                if (! isset($expectedByCtp[$ctpCode])) {
                    $expectedByCtp[$ctpCode] = [
                        'base_cents' => 0,
                        'employee_cents' => 0,
                        'employer_cents' => 0,
                        'employees' => [],
                    ];
                }

                $expectedByCtp[$ctpCode]['base_cents'] += ($line['base_cents'] ?? 0);
                $expectedByCtp[$ctpCode]['employee_cents'] += ($line['employee_cents'] ?? 0);
                $expectedByCtp[$ctpCode]['employer_cents'] += ($line['employer_cents'] ?? 0);

                $employeeId = $block->identity->employeeId;
                if (! in_array($employeeId, $expectedByCtp[$ctpCode]['employees'], true)) {
                    $expectedByCtp[$ctpCode]['employees'][] = $employeeId;
                }
            }
        }

        // Compare with declared aggregates
        $declaredByCtp = [];
        foreach ($payload->ctpAggregates as $aggregate) {
            /** @var DsnCtpAggregate $aggregate */
            $declaredByCtp[$aggregate->ctpCode] = $aggregate;
        }

        // Check each expected CTP has a matching aggregate
        foreach ($expectedByCtp as $ctpCode => $expected) {
            if (! isset($declaredByCtp[$ctpCode])) {
                $entries[] = [
                    'severity' => DsnValidationResult::SEVERITY_ERROR,
                    'category' => 'totals',
                    'rubrique' => 'S21.G00.22',
                    'field' => "ctp_aggregate.{$ctpCode}",
                    'message' => "CTP {$ctpCode}: expected aggregate not found in payload",
                ];

                continue;
            }

            $declared = $declaredByCtp[$ctpCode];

            // Tolerance: 1 cent per employee for rounding
            $tolerance = count($expected['employees']);

            if (abs($declared->baseCents - $expected['base_cents']) > $tolerance) {
                $entries[] = [
                    'severity' => DsnValidationResult::SEVERITY_ERROR,
                    'category' => 'totals',
                    'rubrique' => 'S21.G00.22.003',
                    'field' => "ctp_aggregate.{$ctpCode}.base_cents",
                    'message' => "CTP {$ctpCode}: base mismatch — declared {$declared->baseCents}, computed {$expected['base_cents']}",
                ];
            }

            $expectedTotal = $expected['employee_cents'] + $expected['employer_cents'];
            $declaredTotal = $declared->employeeCents + $declared->employerCents;

            if (abs($declaredTotal - $expectedTotal) > $tolerance) {
                $entries[] = [
                    'severity' => DsnValidationResult::SEVERITY_WARNING,
                    'category' => 'totals',
                    'rubrique' => 'S21.G00.22.005',
                    'field' => "ctp_aggregate.{$ctpCode}.total_cents",
                    'message' => "CTP {$ctpCode}: total mismatch — declared {$declaredTotal}, computed {$expectedTotal}",
                ];
            }
        }

        return $entries;
    }

    // ── 5. ISO-8859-1 encoding check ──

    private static function validateEncoding(DsnPayload $payload): array
    {
        $entries = [];

        // Check company fields
        $companyFields = [
            'name' => $payload->company->name,
            'addressStreet' => $payload->company->addressStreet,
            'addressComplement' => $payload->company->addressComplement,
            'addressCity' => $payload->company->addressCity,
        ];

        foreach ($companyFields as $field => $value) {
            if ($value !== null && ! self::isIso88591Compatible($value)) {
                $entries[] = [
                    'severity' => DsnValidationResult::SEVERITY_WARNING,
                    'category' => 'encoding',
                    'rubrique' => self::companyFieldToRubrique($field),
                    'field' => "company.{$field}",
                    'message' => "Company field '{$field}' contains characters not representable in ISO-8859-1",
                ];
            }
        }

        // Check employee fields
        foreach ($payload->employees as $block) {
            /** @var DsnEmployeeBlock $block */
            $employee = $block->identity;
            $employeeFields = [
                'lastName' => $employee->lastName,
                'firstName' => $employee->firstName,
                'addressStreet' => $employee->addressStreet,
                'addressCity' => $employee->addressCity,
                'birthCity' => $employee->birthCity,
            ];

            foreach ($employeeFields as $field => $value) {
                if ($value !== null && ! self::isIso88591Compatible($value)) {
                    $entries[] = [
                        'severity' => DsnValidationResult::SEVERITY_WARNING,
                        'category' => 'encoding',
                        'rubrique' => self::employeeFieldToRubrique($field),
                        'field' => "employee.{$field}",
                        'message' => "Employee {$employee->lastName}: field '{$field}' contains characters not representable in ISO-8859-1",
                        'employee_id' => $employee->employeeId,
                        'payroll_line_id' => $block->payrollLineId,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * Check if a string contains only ISO-8859-1 compatible characters.
     */
    public static function isIso88591Compatible(string $value): bool
    {
        $converted = @mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
        $roundTrip = mb_convert_encoding($converted, 'UTF-8', 'ISO-8859-1');

        return $roundTrip === $value;
    }

    // ── Rubrique mapping helpers ──

    private static function fieldToRubrique(string $field): string
    {
        return match ($field) {
            'nir' => 'S21.G00.30.001',
            'lastName' => 'S21.G00.30.002',
            'firstName' => 'S21.G00.30.004',
            'gender' => 'S21.G00.30.005',
            'birthDate' => 'S21.G00.30.006',
            'birthCity' => 'S21.G00.30.007',
            default => 'S21.G00.30',
        };
    }

    private static function companyFieldToRubrique(string $field): string
    {
        return match ($field) {
            'name' => 'S21.G00.06.002',
            'addressStreet' => 'S21.G00.06.003',
            'addressComplement' => 'S21.G00.06.004',
            'addressCity' => 'S21.G00.06.006',
            default => 'S21.G00.06',
        };
    }

    private static function employeeFieldToRubrique(string $field): string
    {
        return match ($field) {
            'lastName' => 'S21.G00.30.002',
            'firstName' => 'S21.G00.30.004',
            'addressStreet' => 'S21.G00.30.008',
            'addressCity' => 'S21.G00.30.010',
            'birthCity' => 'S21.G00.30.007',
            default => 'S21.G00.30',
        };
    }
}
