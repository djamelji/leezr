<?php

namespace Tests\Feature;

use App\Core\Workforce\Dsn\DsnBlockSerializer;
use App\Core\Workforce\Dsn\DsnCompanyData;
use App\Core\Workforce\Dsn\DsnCtpAggregate;
use App\Core\Workforce\Dsn\DsnCtpMapping;
use App\Core\Workforce\Dsn\DsnEmployeeBlock;
use App\Core\Workforce\Dsn\DsnEmployeeData;
use App\Core\Workforce\Dsn\DsnPayload;
use App\Core\Workforce\Dsn\DsnValidationResult;
use App\Core\Workforce\Dsn\DsnValidator;
use Tests\TestCase;

/**
 * DSN Serialization + Validation conformity tests (Sprint 6.3).
 *
 * Tests cover:
 * - DsnPayload construction and toArray()
 * - DsnBlockSerializer NEODES Phase 3 format compliance
 * - DsnValidator 5 validation categories
 * - Round-trip data integrity
 * - Edge cases
 */
class DsnSerializationTest extends TestCase
{
    // ── Helpers ──

    private function buildCompanyData(array $overrides = []): DsnCompanyData
    {
        return new DsnCompanyData(
            companyId: $overrides['companyId'] ?? 1,
            name: $overrides['name'] ?? 'Leezr SAS',
            siret: array_key_exists('siret', $overrides) ? $overrides['siret'] : '73282932000074',
            siren: array_key_exists('siren', $overrides) ? $overrides['siren'] : '732829320',
            nic: array_key_exists('nic', $overrides) ? $overrides['nic'] : '00074',
            nafCode: $overrides['nafCode'] ?? '6201Z',
            addressStreet: $overrides['addressStreet'] ?? '15 rue de la Paix',
            addressComplement: $overrides['addressComplement'] ?? null,
            addressPostalCode: $overrides['addressPostalCode'] ?? '75002',
            addressCity: $overrides['addressCity'] ?? 'Paris',
            addressInseeCode: $overrides['addressInseeCode'] ?? '75102',
            addressCountryCode: $overrides['addressCountryCode'] ?? 'FR',
            averageHeadcount: $overrides['averageHeadcount'] ?? 25,
            idcc: $overrides['idcc'] ?? '1486',
        );
    }

    private function buildEmployeeData(array $overrides = []): DsnEmployeeData
    {
        return new DsnEmployeeData(
            employeeId: $overrides['employeeId'] ?? 1,
            employeeNumber: $overrides['employeeNumber'] ?? 'EMP-001',
            firstName: $overrides['firstName'] ?? 'Marie',
            lastName: $overrides['lastName'] ?? 'Dupont',
            email: $overrides['email'] ?? 'marie@test.com',
            hireDate: $overrides['hireDate'] ?? '2024-01-15',
            nir: array_key_exists('nir', $overrides) ? $overrides['nir'] : '2 85 12 75 108 042 29',
            gender: array_key_exists('gender', $overrides) ? $overrides['gender'] : 'F',
            birthDate: array_key_exists('birthDate', $overrides) ? $overrides['birthDate'] : '1985-12-15',
            birthCity: $overrides['birthCity'] ?? 'Paris',
            birthDepartment: $overrides['birthDepartment'] ?? '75',
            birthCountry: $overrides['birthCountry'] ?? 'FR',
            nationality: $overrides['nationality'] ?? 'FR',
            addressStreet: $overrides['addressStreet'] ?? '10 rue de Rivoli',
            addressPostalCode: $overrides['addressPostalCode'] ?? '75001',
            addressCity: $overrides['addressCity'] ?? 'Paris',
            addressCountryCode: $overrides['addressCountryCode'] ?? 'FR',
        );
    }

    private function buildEmployeeBlock(array $overrides = []): DsnEmployeeBlock
    {
        $identity = $overrides['identity'] ?? $this->buildEmployeeData();

        return new DsnEmployeeBlock(
            identity: $identity,
            contract: $overrides['contract'] ?? [
                'start_date' => '2024-01-15',
                'status_code' => '01',
                'nature_code' => '01',
                'contract_number' => 'C-2024-001',
                'company_weekly_hours_hundredths' => '3500',
                'contract_weekly_hours_hundredths' => '3500',
                'pcs_code' => '388a',
                'idcc' => '1486',
            ],
            payment: $overrides['payment'] ?? [
                'payment_date' => '2026-01-31',
                'net_taxable_cents' => 250000,
                'net_payable_cents' => 220000,
                'tax_rate_bps' => 1150,
                'tax_rate_type' => '01',
                'tax_cents' => 30000,
            ],
            remunerationLines: $overrides['remunerationLines'] ?? [
                [
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-01-31',
                    'type_code' => '001',
                    'hours' => 151.67,
                    'amount_cents' => 300000,
                ],
                [
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-01-31',
                    'type_code' => '002',
                    'hours' => 8.0,
                    'amount_cents' => 25000,
                ],
            ],
            csgCrdsLines: $overrides['csgCrdsLines'] ?? [
                [
                    'base_code' => '02',
                    'period_start' => '2026-01-01',
                    'period_end' => '2026-01-31',
                    'amount_cents' => 319263,
                ],
            ],
            contributionLines: $overrides['contributionLines'] ?? [
                ['code' => 'urssaf_maladie', 'base_cents' => 325000, 'employee_cents' => 0, 'employer_cents' => 23400],
                ['code' => 'urssaf_vieillesse_deplaf', 'base_cents' => 325000, 'employee_cents' => 1300, 'employer_cents' => 6013],
                ['code' => 'urssaf_vieillesse_plaf', 'base_cents' => 325000, 'employee_cents' => 22425, 'employer_cents' => 27788],
                ['code' => 'allocations_familiales', 'base_cents' => 325000, 'employee_cents' => 0, 'employer_cents' => 17225],
                ['code' => 'retraite_t1', 'base_cents' => 325000, 'employee_cents' => 12675, 'employer_cents' => 20475],
                ['code' => 'ceg_t1', 'base_cents' => 325000, 'employee_cents' => 2763, 'employer_cents' => 4875],
                ['code' => 'chomage', 'base_cents' => 325000, 'employee_cents' => 0, 'employer_cents' => 13650],
            ],
            payrollLineId: $overrides['payrollLineId'] ?? 1,
        );
    }

    private function buildCtpAggregates(array $employeeBlocks): array
    {
        $byCtp = [];

        foreach ($employeeBlocks as $block) {
            foreach ($block->contributionLines as $line) {
                $ctpCode = DsnCtpMapping::ctpCode($line['code']);
                if ($ctpCode === null) {
                    continue;
                }

                if (! isset($byCtp[$ctpCode])) {
                    $mapping = DsnCtpMapping::get($line['code']);
                    $byCtp[$ctpCode] = [
                        'label' => $mapping['ctp_label'],
                        'base' => 0,
                        'ee' => 0,
                        'er' => 0,
                        'employees' => [],
                    ];
                }

                $byCtp[$ctpCode]['base'] += ($line['base_cents'] ?? 0);
                $byCtp[$ctpCode]['ee'] += ($line['employee_cents'] ?? 0);
                $byCtp[$ctpCode]['er'] += ($line['employer_cents'] ?? 0);
                if (! in_array($block->identity->employeeId, $byCtp[$ctpCode]['employees'], true)) {
                    $byCtp[$ctpCode]['employees'][] = $block->identity->employeeId;
                }
            }
        }

        $aggregates = [];
        foreach ($byCtp as $ctpCode => $data) {
            $aggregates[] = new DsnCtpAggregate(
                ctpCode: $ctpCode,
                ctpLabel: $data['label'],
                baseCents: $data['base'],
                employeeCents: $data['ee'],
                employerCents: $data['er'],
                employeeCount: count($data['employees']),
            );
        }

        return $aggregates;
    }

    private function buildPayload(array $overrides = []): DsnPayload
    {
        $employees = $overrides['employees'] ?? [$this->buildEmployeeBlock()];
        $ctpAggregates = $overrides['ctpAggregates'] ?? $this->buildCtpAggregates($employees);

        return new DsnPayload(
            declarationType: $overrides['declarationType'] ?? 'mensuelle',
            nature: $overrides['nature'] ?? '01',
            periodStart: $overrides['periodStart'] ?? '2026-01-01',
            periodEnd: $overrides['periodEnd'] ?? '2026-01-31',
            fraction: $overrides['fraction'] ?? '11',
            payrollRunId: $overrides['payrollRunId'] ?? 42,
            company: $overrides['company'] ?? $this->buildCompanyData(),
            employees: $employees,
            ctpAggregates: $ctpAggregates,
            createdAt: $overrides['createdAt'] ?? '2026-02-01T10:00:00+01:00',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnPayload DTO
    // ═══════════════════════════════════════════════════════════════

    public function test_payload_constructs_with_valid_data(): void
    {
        $payload = $this->buildPayload();

        $this->assertSame('mensuelle', $payload->declarationType);
        $this->assertSame('01', $payload->nature);
        $this->assertSame(42, $payload->payrollRunId);
        $this->assertCount(1, $payload->employees);
        $this->assertNotEmpty($payload->ctpAggregates);
    }

    public function test_payload_to_array_contains_all_sections(): void
    {
        $payload = $this->buildPayload();
        $arr = $payload->toArray();

        $this->assertArrayHasKey('declaration_type', $arr);
        $this->assertArrayHasKey('period_start', $arr);
        $this->assertArrayHasKey('company', $arr);
        $this->assertArrayHasKey('employees', $arr);
        $this->assertArrayHasKey('ctp_aggregates', $arr);
        $this->assertSame(1, $arr['employees_count']);
    }

    public function test_employee_block_to_array_structure(): void
    {
        $block = $this->buildEmployeeBlock();
        $arr = $block->toArray();

        $this->assertArrayHasKey('employee_id', $arr);
        $this->assertArrayHasKey('identity', $arr);
        $this->assertArrayHasKey('contract', $arr);
        $this->assertArrayHasKey('payment', $arr);
        $this->assertArrayHasKey('remuneration_lines', $arr);
        $this->assertArrayHasKey('csg_crds_lines', $arr);
        $this->assertArrayHasKey('contribution_lines', $arr);
    }

    public function test_ctp_aggregate_to_array(): void
    {
        $agg = new DsnCtpAggregate(
            ctpCode: '100',
            ctpLabel: 'RG CAS GENERAL',
            baseCents: 650000,
            employeeCents: 2600,
            employerCents: 46800,
            employeeCount: 2,
        );

        $arr = $agg->toArray();
        $this->assertSame('100', $arr['ctp_code']);
        $this->assertSame(650000, $arr['base_cents']);
        $this->assertSame(2, $arr['employee_count']);
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnBlockSerializer — Format compliance
    // ═══════════════════════════════════════════════════════════════

    public function test_serializer_produces_non_empty_output(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        $this->assertNotEmpty($output);
        $this->assertIsString($output);
    }

    public function test_serializer_format_rubrique_comma_quoted_value(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);
        $lines = explode("\n", $output);

        // Every line must match pattern: S21.G00.XX.YYY,'value'
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression(
                "/^S21\.G00\.\d{2}\.\d{3},'[^']*'$/",
                $line,
                "Line does not match DSN format: {$line}"
            );
        }
    }

    public function test_serializer_contains_etablissement_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        $this->assertStringContainsString("S21.G00.06.001,'00074'", $output); // NIC
        $this->assertStringContainsString("S21.G00.06.003,'75002'", $output); // CP
        $this->assertStringContainsString("S21.G00.06.004,'Paris'", $output); // City
        $this->assertStringContainsString("S21.G00.06.008,'6201Z'", $output); // NAF
        $this->assertStringContainsString("S21.G00.06.300,'1486'", $output);  // IDCC
    }

    public function test_serializer_contains_individu_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        // NIR without spaces
        $this->assertStringContainsString("S21.G00.30.001,'2851275108042", $output);
        // Last name uppercased
        $this->assertStringContainsString("S21.G00.30.002,'DUPONT'", $output);
        // First name
        $this->assertStringContainsString("S21.G00.30.004,'Marie'", $output);
        // Gender F = 02
        $this->assertStringContainsString("S21.G00.30.005,'02'", $output);
        // Birth date ddmmyyyy
        $this->assertStringContainsString("S21.G00.30.006,'15121985'", $output);
    }

    public function test_serializer_contains_contrat_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        $this->assertStringContainsString("S21.G00.40.001,'15012024'", $output); // Start date
        $this->assertStringContainsString("S21.G00.40.002,'01'", $output);       // Status
        $this->assertStringContainsString("S21.G00.40.009,'C-2024-001'", $output); // Contract number
        $this->assertStringContainsString("S21.G00.40.012,'3500'", $output);      // Weekly hours
    }

    public function test_serializer_contains_versement_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        $this->assertStringContainsString("S21.G00.50.001,'31012026'", $output);     // Payment date
        $this->assertStringContainsString("S21.G00.50.002,'2500.00'", $output);      // Net taxable
        $this->assertStringContainsString("S21.G00.50.004,'2200.00'", $output);      // Net payable
        $this->assertStringContainsString("S21.G00.50.006,'11.50'", $output);        // Tax rate %
        $this->assertStringContainsString("S21.G00.50.008,'300.00'", $output);       // Tax amount
    }

    public function test_serializer_contains_remuneration_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        // Base salary line
        $this->assertStringContainsString("S21.G00.51.011,'001'", $output);  // Type salaire brut
        $this->assertStringContainsString("S21.G00.51.012,'151.67'", $output); // Hours
        $this->assertStringContainsString("S21.G00.51.013,'3000.00'", $output); // Amount
        // Overtime line
        $this->assertStringContainsString("S21.G00.51.011,'002'", $output);
    }

    public function test_serializer_contains_base_assujettie_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        $this->assertStringContainsString("S21.G00.78.001,'02'", $output);       // Base code
        $this->assertStringContainsString("S21.G00.78.004,'3192.63'", $output);  // Amount
    }

    public function test_serializer_contains_cotisation_individuelle_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        // At least one S21.G00.81 line
        $this->assertStringContainsString("S21.G00.81.001,", $output);
        $this->assertStringContainsString("S21.G00.81.003,", $output); // Base
        $this->assertStringContainsString("S21.G00.81.004,", $output); // Employee
    }

    public function test_serializer_contains_ctp_aggregate_bloc(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);

        // CTP 100 aggregate should be present
        $this->assertStringContainsString("S21.G00.22.001,'100'", $output);
        $this->assertStringContainsString("S21.G00.22.004,", $output); // Base
        $this->assertStringContainsString("S21.G00.22.006,", $output); // Effectif
    }

    // ── Formatting helpers ──

    public function test_format_cents_to_dsn_decimal(): void
    {
        $this->assertSame('0.00', DsnBlockSerializer::formatCents(0));
        $this->assertSame('12.34', DsnBlockSerializer::formatCents(1234));
        $this->assertSame('100.00', DsnBlockSerializer::formatCents(10000));
        $this->assertSame('3250.00', DsnBlockSerializer::formatCents(325000));
        $this->assertSame('-12.50', DsnBlockSerializer::formatCents(-1250));
    }

    public function test_format_bps_as_percent(): void
    {
        $this->assertSame('0.00', DsnBlockSerializer::formatBpsAsPercent(0));
        $this->assertSame('11.50', DsnBlockSerializer::formatBpsAsPercent(1150));
        $this->assertSame('43.00', DsnBlockSerializer::formatBpsAsPercent(4300));
        $this->assertSame('0.50', DsnBlockSerializer::formatBpsAsPercent(50));
    }

    public function test_format_date_ymd_to_ddmmyyyy(): void
    {
        $this->assertSame('15012024', DsnBlockSerializer::formatDate('2024-01-15'));
        $this->assertSame('31122026', DsnBlockSerializer::formatDate('2026-12-31'));
        $this->assertSame('', DsnBlockSerializer::formatDate(''));
        $this->assertSame('', DsnBlockSerializer::formatDate(null));
    }

    public function test_format_nir_strips_spaces_and_dashes(): void
    {
        $this->assertSame('285127510804229', DsnBlockSerializer::formatNir('2 85 12 75 108 042 29'));
        $this->assertSame('285127510804229', DsnBlockSerializer::formatNir('2-85-12-75-108-042-29'));
        $this->assertSame('285127510804229', DsnBlockSerializer::formatNir('285127510804229'));
        $this->assertSame('', DsnBlockSerializer::formatNir(null));
    }

    public function test_serializer_sanitizes_single_quotes_in_values(): void
    {
        $company = $this->buildCompanyData(['addressStreet' => "15 rue de l'Arbre"]);
        $payload = $this->buildPayload(['company' => $company]);
        $output = DsnBlockSerializer::serialize($payload);

        // Single quote replaced by space
        $this->assertStringContainsString("S21.G00.06.002,'15 rue de l Arbre'", $output);
        // No unmatched quotes
        $this->assertStringNotContainsString("l'Arbre", $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnValidator — 5 categories
    // ═══════════════════════════════════════════════════════════════

    public function test_validator_accepts_valid_payload(): void
    {
        $payload = $this->buildPayload();
        $result = DsnValidator::validate($payload);

        $this->assertTrue($result->valid, 'Valid payload should pass: ' . json_encode($result->entries));
        $this->assertEmpty($result->entries);
    }

    public function test_validator_result_ok_factory(): void
    {
        $result = DsnValidationResult::ok();
        $this->assertTrue($result->valid);
        $this->assertEmpty($result->entries);
    }

    public function test_validator_result_fail_factory(): void
    {
        $errors = [['category' => 'test', 'field' => 'x', 'message' => 'error']];
        $result = DsnValidationResult::fromEntries($errors);
        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->entries);
    }

    public function test_validator_result_errors_by_category(): void
    {
        $errors = [
            ['category' => 'siret', 'field' => 'a', 'message' => 'err1'],
            ['category' => 'nir', 'field' => 'b', 'message' => 'err2'],
            ['category' => 'siret', 'field' => 'c', 'message' => 'err3'],
        ];
        $result = DsnValidationResult::fromEntries($errors);

        $this->assertCount(2, $result->errorsByCategory('siret'));
        $this->assertCount(1, $result->errorsByCategory('nir'));
        $this->assertCount(0, $result->errorsByCategory('encoding'));
    }

    // ── 1. SIRET validation ──

    public function test_validator_rejects_missing_siret(): void
    {
        $company = $this->buildCompanyData(['siret' => null, 'siren' => null, 'nic' => null]);
        $payload = $this->buildPayload(['company' => $company]);
        $result = DsnValidator::validate($payload);

        $this->assertFalse($result->valid);
        $siretErrors = $result->errorsByCategory('siret');
        $this->assertNotEmpty($siretErrors);
        $this->assertStringContainsString('required', $siretErrors[0]['message']);
    }

    public function test_validator_rejects_invalid_siret_luhn(): void
    {
        $company = $this->buildCompanyData(['siret' => '12345678901234']);
        $payload = $this->buildPayload(['company' => $company]);
        $result = DsnValidator::validate($payload);

        $this->assertFalse($result->valid);
        $siretErrors = $result->errorsByCategory('siret');
        $this->assertNotEmpty($siretErrors);
        $this->assertStringContainsString('Luhn', $siretErrors[0]['message']);
    }

    // ── 2. NIR validation ──

    public function test_validator_rejects_missing_nir(): void
    {
        $employee = $this->buildEmployeeData(['nir' => null]);
        $block = $this->buildEmployeeBlock(['identity' => $employee]);
        $payload = $this->buildPayload(['employees' => [$block]]);
        $result = DsnValidator::validate($payload);

        $this->assertFalse($result->valid);
        $nirErrors = $result->errorsByCategory('nir');
        $this->assertNotEmpty($nirErrors);
    }

    public function test_validator_rejects_invalid_nir_modulo(): void
    {
        $employee = $this->buildEmployeeData(['nir' => '285127510804200']);
        $block = $this->buildEmployeeBlock(['identity' => $employee]);
        $payload = $this->buildPayload(['employees' => [$block]]);
        $result = DsnValidator::validate($payload);

        $this->assertFalse($result->valid);
        $nirErrors = $result->errorsByCategory('nir');
        $hasModuloError = false;
        foreach ($nirErrors as $err) {
            if (str_contains($err['message'], 'modulo-97')) {
                $hasModuloError = true;
            }
        }
        $this->assertTrue($hasModuloError, 'Should have modulo-97 error');
    }

    public function test_validator_rejects_missing_mandatory_employee_fields(): void
    {
        $employee = $this->buildEmployeeData(['gender' => null, 'birthDate' => null]);
        $block = $this->buildEmployeeBlock(['identity' => $employee]);
        $payload = $this->buildPayload(['employees' => [$block]]);
        $result = DsnValidator::validate($payload);

        $this->assertFalse($result->valid);
        $nirErrors = $result->errorsByCategory('nir');
        $fields = array_column($nirErrors, 'field');
        $this->assertContains('employee.gender', $fields);
        $this->assertContains('employee.birth_date', $fields);
    }

    // ── 3. CTP mapping validation ──

    public function test_validator_rejects_unmapped_contribution_code(): void
    {
        $block = $this->buildEmployeeBlock([
            'contributionLines' => [
                ['code' => 'unknown_custom_code', 'base_cents' => 100000, 'employee_cents' => 500, 'employer_cents' => 1000],
            ],
        ]);
        $payload = $this->buildPayload(['employees' => [$block]]);
        $result = DsnValidator::validate($payload);

        $this->assertFalse($result->valid);
        $ctpErrors = $result->errorsByCategory('ctp');
        $this->assertNotEmpty($ctpErrors);
        $this->assertStringContainsString('unknown_custom_code', $ctpErrors[0]['message']);
    }

    public function test_validator_accepts_csg_crds_without_ctp(): void
    {
        $block = $this->buildEmployeeBlock([
            'contributionLines' => [
                ['code' => 'csg_deductible', 'base_cents' => 319263, 'employee_cents' => 21747, 'employer_cents' => 0],
                ['code' => 'csg_non_deductible', 'base_cents' => 319263, 'employee_cents' => 7662, 'employer_cents' => 0],
                ['code' => 'crds', 'base_cents' => 319263, 'employee_cents' => 1596, 'employer_cents' => 0],
            ],
        ]);
        // No CTP aggregates needed for CSG/CRDS
        $payload = $this->buildPayload([
            'employees' => [$block],
            'ctpAggregates' => [],
        ]);
        $result = DsnValidator::validate($payload);

        $this->assertTrue($result->valid, 'CSG/CRDS should not require CTP: ' . json_encode($result->entries));
    }

    // ── 4. Totals coherence ──

    public function test_validator_rejects_mismatched_ctp_totals(): void
    {
        $block = $this->buildEmployeeBlock();

        // Manually create wrong aggregates
        $wrongAgg = [
            new DsnCtpAggregate(
                ctpCode: '100',
                ctpLabel: 'RG CAS GENERAL',
                baseCents: 999999,  // Wrong!
                employeeCents: 0,
                employerCents: 0,
                employeeCount: 1,
            ),
        ];

        $payload = $this->buildPayload([
            'employees' => [$block],
            'ctpAggregates' => $wrongAgg,
        ]);
        $result = DsnValidator::validate($payload);

        $this->assertFalse($result->valid);
        $totalErrors = $result->errorsByCategory('totals');
        $this->assertNotEmpty($totalErrors);
    }

    public function test_validator_accepts_correct_totals(): void
    {
        $payload = $this->buildPayload(); // Auto-computes correct aggregates
        $result = DsnValidator::validate($payload);

        $totalErrors = $result->errorsByCategory('totals');
        $this->assertEmpty($totalErrors, 'Correct totals should pass: ' . json_encode($totalErrors));
    }

    // ── 5. Encoding validation ──

    public function test_validator_accepts_iso_8859_1_characters(): void
    {
        // French accents are all in ISO-8859-1
        $company = $this->buildCompanyData(['addressCity' => 'Châteauneuf-lès-Bains']);
        $payload = $this->buildPayload(['company' => $company]);
        $result = DsnValidator::validate($payload);

        $encodingErrors = $result->errorsByCategory('encoding');
        $this->assertEmpty($encodingErrors, 'ISO-8859-1 chars should pass: ' . json_encode($encodingErrors));
    }

    public function test_validator_warns_non_iso_8859_1_characters(): void
    {
        // Chinese/Japanese characters are NOT in ISO-8859-1 — warning, not error
        $company = $this->buildCompanyData(['name' => 'Leezr 株式会社']);
        $payload = $this->buildPayload(['company' => $company]);
        $result = DsnValidator::validate($payload);

        // Encoding issues are warnings — payload is still valid
        $this->assertTrue($result->valid);
        $encodingWarnings = $result->warnings();
        $this->assertNotEmpty($encodingWarnings);
        $this->assertStringContainsString('ISO-8859-1', $encodingWarnings[0]['message']);
    }

    public function test_validator_warns_emoji_in_employee_name(): void
    {
        $employee = $this->buildEmployeeData(['firstName' => 'Marie 🌟']);
        $block = $this->buildEmployeeBlock(['identity' => $employee]);
        $payload = $this->buildPayload(['employees' => [$block]]);
        $result = DsnValidator::validate($payload);

        // Encoding issues are warnings — payload is still valid
        $this->assertTrue($result->valid);
        $encodingWarnings = $result->warnings();
        $this->assertNotEmpty($encodingWarnings);
    }

    public function test_iso_8859_1_check_helper(): void
    {
        $this->assertTrue(DsnValidator::isIso88591Compatible('Hello World'));
        $this->assertTrue(DsnValidator::isIso88591Compatible('Château'));
        $this->assertTrue(DsnValidator::isIso88591Compatible('François'));
        $this->assertTrue(DsnValidator::isIso88591Compatible('Müller'));
        // € (U+20AC) is NOT in ISO-8859-1 (it's in ISO-8859-15)
        $this->assertFalse(DsnValidator::isIso88591Compatible('€'));
        $this->assertFalse(DsnValidator::isIso88591Compatible('株式会社'));
        $this->assertFalse(DsnValidator::isIso88591Compatible('🌟'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Round-trip / Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function test_serializer_handles_multiple_employees(): void
    {
        $emp1 = $this->buildEmployeeBlock(['payrollLineId' => 1]);
        $emp2 = $this->buildEmployeeBlock([
            'identity' => $this->buildEmployeeData([
                'employeeId' => 2,
                'firstName' => 'Jean',
                'lastName' => 'Martin',
                'nir' => '1 90 05 75 115 035 64',
                'gender' => 'M',
                'birthDate' => '1990-05-20',
            ]),
            'payrollLineId' => 2,
        ]);

        $employees = [$emp1, $emp2];
        $payload = $this->buildPayload(['employees' => $employees]);
        $output = DsnBlockSerializer::serialize($payload);

        // Both employees present
        $this->assertStringContainsString("'DUPONT'", $output);
        $this->assertStringContainsString("'MARTIN'", $output);
        // Both NIRs
        $this->assertStringContainsString('285127510804229', $output);
        $this->assertStringContainsString('190057511503564', $output);
    }

    public function test_serializer_handles_zero_amounts(): void
    {
        $block = $this->buildEmployeeBlock([
            'payment' => [
                'payment_date' => '2026-01-31',
                'net_taxable_cents' => 0,
                'net_payable_cents' => 0,
                'tax_rate_bps' => 0,
                'tax_rate_type' => '01',
                'tax_cents' => 0,
            ],
        ]);

        $payload = $this->buildPayload(['employees' => [$block]]);
        $output = DsnBlockSerializer::serialize($payload);

        $this->assertStringContainsString("S21.G00.50.002,'0.00'", $output);
        $this->assertStringContainsString("S21.G00.50.004,'0.00'", $output);
    }

    public function test_serializer_optional_complement_absent(): void
    {
        $company = $this->buildCompanyData(['addressComplement' => null]);
        $payload = $this->buildPayload(['company' => $company]);
        $output = DsnBlockSerializer::serialize($payload);

        // S21.G00.06.005 should NOT be in output
        $this->assertStringNotContainsString('S21.G00.06.005', $output);
    }

    public function test_serializer_optional_complement_present(): void
    {
        $company = $this->buildCompanyData(['addressComplement' => 'Bâtiment B']);
        $payload = $this->buildPayload(['company' => $company]);
        $output = DsnBlockSerializer::serialize($payload);

        $this->assertStringContainsString("S21.G00.06.005,'Bâtiment B'", $output);
    }

    public function test_payload_and_validation_result_to_array(): void
    {
        $payload = $this->buildPayload();
        $result = DsnValidator::validate($payload);

        $payloadArr = $payload->toArray();
        $resultArr = $result->toArray();

        $this->assertArrayHasKey('declaration_type', $payloadArr);
        $this->assertArrayHasKey('valid', $resultArr);
        $this->assertTrue($resultArr['valid']);
        $this->assertSame(0, $resultArr['error_count']);
    }

    public function test_serializer_bloc_ordering(): void
    {
        $payload = $this->buildPayload();
        $output = DsnBlockSerializer::serialize($payload);
        $lines = explode("\n", $output);

        // Find positions of bloc types
        $firstEtab = $firstCtp = $firstIndividu = $firstContrat = null;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'S21.G00.06') && $firstEtab === null) {
                $firstEtab = $i;
            }
            if (str_starts_with($line, 'S21.G00.22') && $firstCtp === null) {
                $firstCtp = $i;
            }
            if (str_starts_with($line, 'S21.G00.30') && $firstIndividu === null) {
                $firstIndividu = $i;
            }
            if (str_starts_with($line, 'S21.G00.40') && $firstContrat === null) {
                $firstContrat = $i;
            }
        }

        // Établissement before CTP before Individu before Contrat
        $this->assertNotNull($firstEtab);
        $this->assertNotNull($firstCtp);
        $this->assertNotNull($firstIndividu);
        $this->assertNotNull($firstContrat);
        $this->assertLessThan($firstCtp, $firstEtab);
        $this->assertLessThan($firstIndividu, $firstCtp);
        $this->assertLessThan($firstContrat, $firstIndividu);
    }

    public function test_serializer_line_count(): void
    {
        $payload = $this->buildPayload();
        $lineCount = DsnBlockSerializer::lineCount($payload);

        // Should have multiple lines (at least 30+ for a single employee)
        $this->assertGreaterThan(30, $lineCount);
    }

    public function test_full_roundtrip_valid_payload_serializes_without_error(): void
    {
        $payload = $this->buildPayload();

        // 1. Validate
        $validationResult = DsnValidator::validate($payload);
        $this->assertTrue($validationResult->valid, 'Payload must be valid');

        // 2. Serialize
        $output = DsnBlockSerializer::serialize($payload);
        $this->assertNotEmpty($output);

        // 3. Every line follows DSN format
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression(
                "/^S21\.G00\.\d{2}\.\d{3},'[^']*'$/",
                $line,
            );
        }

        // 4. toArray for audit trail
        $arr = $payload->toArray();
        $this->assertSame(42, $arr['payroll_run_id']);
    }
}
