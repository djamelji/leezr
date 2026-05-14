<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Markets\MarketRuleSet;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\DsnBlockSerializer;
use App\Core\Workforce\Dsn\DsnCtpMapping;
use App\Core\Workforce\Dsn\DsnValidator;
use App\Core\Workforce\Dsn\DsnValidationResult;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ExportPayrollDsnUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 6.6 — DSN hardening pre-Net-Entreprises.
 *
 * Tests: versioned CTP mapping, enriched validation report,
 * regenerate guards, payload hash audit.
 *
 * ADR-524
 */
class DsnHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr-FR',
            'timezone' => 'Europe/Paris',
            'vat_rate_bps' => 2000,
            'dial_code' => '+33',
            'flag_code' => 'fr',
            'flag_svg' => '',
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Hardening Co',
            'slug' => 'hardening-co',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
            'siret' => '73282932000074',
            'naf_code' => '6201Z',
            'address_street' => '15 rue de la Paix',
            'address_postal_code' => '75002',
            'address_city' => 'Paris',
            'address_country_code' => 'FR',
            'average_headcount' => 25,
        ]);

        $this->user = User::create([
            'name' => 'Test Operator',
            'email' => 'operator@hardening.test',
            'password' => bcrypt('password'),
        ]);

        DsnCtpMapping::clearCache();

        Model::reguard();
    }

    protected function tearDown(): void
    {
        DsnCtpMapping::clearCache();
        app()->forgetInstance('company.context');
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. CTP mapping versionné
    // ═══════════════════════════════════════════════════════════════

    public function test_ctp_static_fallback_works(): void
    {
        // No MarketRuleSet records → static MAP used
        $info = DsnCtpMapping::get('urssaf_maladie');

        $this->assertNotNull($info);
        $this->assertSame('100', $info['ctp_code']);
        $this->assertSame('RG CAS GENERAL', $info['ctp_label']);
        $this->assertSame('S21.G00.22', $info['dsn_bloc']);
    }

    public function test_ctp_static_returns_null_for_unknown(): void
    {
        $this->assertNull(DsnCtpMapping::get('nonexistent_code'));
    }

    public function test_ctp_versioned_overrides_static(): void
    {
        Model::unguard();
        MarketRuleSet::create([
            'market_key' => 'FR',
            'domain' => 'dsn_ctp',
            'rule_key' => 'urssaf_maladie',
            'value' => [
                'ctp_code' => '999',
                'ctp_label' => 'OVERRIDE TEST',
                'dsn_bloc' => 'S21.G00.22',
            ],
            'effective_from' => '2025-01-01',
            'effective_until' => null,
            'source' => 'law',
        ]);
        Model::reguard();

        DsnCtpMapping::clearCache();

        $info = DsnCtpMapping::get('urssaf_maladie');

        $this->assertSame('999', $info['ctp_code']);
        $this->assertSame('OVERRIDE TEST', $info['ctp_label']);
    }

    public function test_ctp_versioned_date_scoping(): void
    {
        Model::unguard();
        // Rule effective 2026-01-01 to 2026-06-30
        MarketRuleSet::create([
            'market_key' => 'FR',
            'domain' => 'dsn_ctp',
            'rule_key' => 'chomage',
            'value' => [
                'ctp_code' => '773',
                'ctp_label' => 'CHOMAGE 2026',
                'dsn_bloc' => 'S21.G00.22',
            ],
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-06-30',
            'source' => 'law',
        ]);
        Model::reguard();

        // Within range → versioned
        DsnCtpMapping::clearCache();
        $info = DsnCtpMapping::get('chomage', 'FR', '2026-03-15');
        $this->assertSame('773', $info['ctp_code']);

        // Outside range → static fallback
        DsnCtpMapping::clearCache();
        $info = DsnCtpMapping::get('chomage', 'FR', '2026-09-01');
        $this->assertSame('772', $info['ctp_code']);
    }

    public function test_ctp_versioned_adds_new_code(): void
    {
        Model::unguard();
        MarketRuleSet::create([
            'market_key' => 'FR',
            'domain' => 'dsn_ctp',
            'rule_key' => 'prevoyance_cadre',
            'value' => [
                'ctp_code' => '420',
                'ctp_label' => 'PREVOYANCE CADRE',
                'dsn_bloc' => 'S21.G00.22',
            ],
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'source' => 'convention',
        ]);
        Model::reguard();

        DsnCtpMapping::clearCache();

        // New code from versioned — not in static MAP
        $info = DsnCtpMapping::get('prevoyance_cadre');
        $this->assertNotNull($info);
        $this->assertSame('420', $info['ctp_code']);

        // Static still returns null for this code
        $static = DsnCtpMapping::getStatic('prevoyance_cadre');
        $this->assertNull($static);
    }

    public function test_ctp_helper_methods_with_versioned(): void
    {
        Model::unguard();
        MarketRuleSet::create([
            'market_key' => 'FR',
            'domain' => 'dsn_ctp',
            'rule_key' => 'urssaf_maladie',
            'value' => [
                'ctp_code' => '888',
                'ctp_label' => 'OVERRIDE',
                'dsn_bloc' => 'S21.G00.22',
            ],
            'effective_from' => '2025-01-01',
            'effective_until' => null,
            'source' => 'law',
        ]);
        Model::reguard();

        DsnCtpMapping::clearCache();

        $this->assertSame('888', DsnCtpMapping::ctpCode('urssaf_maladie'));
        $this->assertSame('S21.G00.22', DsnCtpMapping::dsnBloc('urssaf_maladie'));
        $this->assertTrue(DsnCtpMapping::hasCtp('urssaf_maladie'));
        $this->assertFalse(DsnCtpMapping::isCsgCrds('urssaf_maladie'));
    }

    public function test_ctp_csg_crds_stays_no_ctp(): void
    {
        // CSG/CRDS should never get a CTP code even from versioned
        $info = DsnCtpMapping::get('csg_deductible');

        $this->assertNotNull($info);
        $this->assertNull($info['ctp_code']);
        $this->assertSame('S21.G00.78', $info['dsn_bloc']);
        $this->assertTrue(DsnCtpMapping::isCsgCrds('csg_deductible'));
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. Validation report enrichi
    // ═══════════════════════════════════════════════════════════════

    public function test_validation_result_severity_error_blocks(): void
    {
        $entries = [
            [
                'severity' => DsnValidationResult::SEVERITY_ERROR,
                'category' => 'siret',
                'rubrique' => 'S21.G00.06.001',
                'field' => 'company.siret',
                'message' => 'SIRET missing',
            ],
        ];

        $result = DsnValidationResult::fromEntries($entries);

        $this->assertFalse($result->valid);
        $this->assertCount(1, $result->errors());
        $this->assertCount(0, $result->warnings());
    }

    public function test_validation_result_severity_warning_does_not_block(): void
    {
        $entries = [
            [
                'severity' => DsnValidationResult::SEVERITY_WARNING,
                'category' => 'encoding',
                'rubrique' => 'S21.G00.30.002',
                'field' => 'employee.lastName',
                'message' => 'Contains non ISO-8859-1 chars',
                'employee_id' => 42,
            ],
        ];

        $result = DsnValidationResult::fromEntries($entries);

        $this->assertTrue($result->valid);
        $this->assertCount(0, $result->errors());
        $this->assertCount(1, $result->warnings());
    }

    public function test_validation_result_mixed_errors_and_warnings(): void
    {
        $entries = [
            [
                'severity' => DsnValidationResult::SEVERITY_ERROR,
                'category' => 'nir',
                'rubrique' => 'S21.G00.30.001',
                'field' => 'employee.nir',
                'message' => 'NIR invalid',
                'employee_id' => 1,
                'payroll_line_id' => 10,
            ],
            [
                'severity' => DsnValidationResult::SEVERITY_WARNING,
                'category' => 'encoding',
                'rubrique' => 'S21.G00.30.002',
                'field' => 'employee.lastName',
                'message' => 'ISO issue',
                'employee_id' => 1,
            ],
        ];

        $result = DsnValidationResult::fromEntries($entries);

        $this->assertFalse($result->valid); // error present
        $this->assertCount(1, $result->errors());
        $this->assertCount(1, $result->warnings());
        $this->assertCount(2, $result->entriesForEmployee(1));
    }

    public function test_validation_result_rubrique_present(): void
    {
        $entries = [
            [
                'severity' => DsnValidationResult::SEVERITY_ERROR,
                'category' => 'siret',
                'rubrique' => 'S21.G00.06.001',
                'field' => 'company.siret',
                'message' => 'test',
            ],
        ];

        $result = DsnValidationResult::fromEntries($entries);

        $this->assertSame('S21.G00.06.001', $result->entries[0]['rubrique']);
    }

    public function test_validation_result_to_array_includes_counts(): void
    {
        $entries = [
            ['severity' => 'error', 'category' => 'a', 'rubrique' => 'X', 'field' => 'x', 'message' => 'e1'],
            ['severity' => 'warning', 'category' => 'b', 'rubrique' => 'Y', 'field' => 'y', 'message' => 'w1'],
            ['severity' => 'warning', 'category' => 'c', 'rubrique' => 'Z', 'field' => 'z', 'message' => 'w2'],
        ];

        $result = DsnValidationResult::fromEntries($entries);
        $arr = $result->toArray();

        $this->assertFalse($arr['valid']);
        $this->assertSame(1, $arr['error_count']);
        $this->assertSame(2, $arr['warning_count']);
        $this->assertCount(3, $arr['entries']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. Regenerate guard
    // ═══════════════════════════════════════════════════════════════

    public function test_can_regenerate_draft(): void
    {
        Model::unguard();
        $decl = $this->createDeclaration(DsnDeclaration::STATUS_DRAFT);
        Model::reguard();

        $this->assertTrue($decl->canRegenerate());
    }

    public function test_can_regenerate_validated(): void
    {
        Model::unguard();
        $decl = $this->createDeclaration(DsnDeclaration::STATUS_VALIDATED);
        Model::reguard();

        $this->assertTrue($decl->canRegenerate());
    }

    public function test_can_regenerate_exported(): void
    {
        Model::unguard();
        $decl = $this->createDeclaration(DsnDeclaration::STATUS_EXPORTED);
        Model::reguard();

        $this->assertTrue($decl->canRegenerate());
    }

    public function test_can_regenerate_rejected(): void
    {
        Model::unguard();
        $decl = $this->createDeclaration(DsnDeclaration::STATUS_REJECTED);
        Model::reguard();

        $this->assertTrue($decl->canRegenerate());
    }

    public function test_cannot_regenerate_submitted(): void
    {
        Model::unguard();
        $decl = $this->createDeclaration(DsnDeclaration::STATUS_SUBMITTED);
        Model::reguard();

        $this->assertFalse($decl->canRegenerate());
    }

    public function test_cannot_regenerate_accepted(): void
    {
        Model::unguard();
        $decl = $this->createDeclaration(DsnDeclaration::STATUS_ACCEPTED);
        Model::reguard();

        $this->assertFalse($decl->canRegenerate());
    }

    public function test_regenerate_blocked_after_submitted_throws(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        // First export
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        // Transition to submitted
        Model::unguard();
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $declaration->transitionTo(DsnDeclaration::STATUS_SUBMITTED);
        $declaration->submission_reference = 'REF-001';
        $declaration->submitted_by = $this->user->id;
        $declaration->submitted_at = now();
        $declaration->save();
        Model::reguard();

        // Try to regenerate — should throw
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot regenerate');
        $useCase->execute($this->payrollRun, $this->user->id);
    }

    public function test_regenerate_allowed_after_rejected(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        // First export
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);
        $oldHash = $declaration->payload_hash;

        // Transition to submitted then rejected
        Model::unguard();
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $declaration->transitionTo(DsnDeclaration::STATUS_SUBMITTED);
        $declaration->submission_reference = 'REF-001';
        $declaration->submitted_by = $this->user->id;
        $declaration->submitted_at = now();
        $declaration->save();

        $declaration->transitionTo(DsnDeclaration::STATUS_REJECTED);
        $declaration->save();
        Model::reguard();

        // Regenerate — should succeed (deletes old, creates new)
        $newDeclaration = $useCase->execute($this->payrollRun, $this->user->id);

        $this->assertNotSame($declaration->id, $newDeclaration->id);
        $this->assertSame($oldHash, $newDeclaration->payload_hash); // Same data → same hash
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. Payload hash audit
    // ═══════════════════════════════════════════════════════════════

    public function test_hash_is_stable_for_same_data(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $useCase = new ExportPayrollDsnUseCase();

        // First run
        $declaration1 = $useCase->execute($this->payrollRun, $this->user->id);
        $hash1 = $declaration1->payload_hash;

        // Delete declaration to allow regeneration
        Model::unguard();
        $declaration1->delete();
        Model::reguard();

        // Second run — same data should produce same hash
        $declaration2 = $useCase->execute($this->payrollRun, $this->user->id);

        $this->assertSame($hash1, $declaration2->payload_hash);
    }

    public function test_hash_changes_when_source_data_changes(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $useCase = new ExportPayrollDsnUseCase();
        $declaration1 = $useCase->execute($this->payrollRun, $this->user->id);
        $hash1 = $declaration1->payload_hash;

        // Delete declaration to allow regeneration
        Model::unguard();
        $declaration1->delete();

        // Change source data by deleting the immutable calculation and recreating with different values
        $this->calculation->forceDelete();
        $line = PayrollLine::withoutGlobalScopes()->where('payroll_run_id', $this->payrollRun->id)->first();
        $this->calculation = PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $line->id,
            'status' => PayrollCalculation::STATUS_VALIDATED,
            'rule_version' => 'payroll-calc-v2',
            'gross_total_cents' => 350000,
            'plafond_ss_monthly_cents' => 383400,
            'contributions_employee_cents' => 67800,
            'contributions_employer_cents' => 128700,
            'total_cost_employer_cents' => 478700,
            'taxable_income_cents' => 282200,
            'tax_breakdown' => ['tax_rate_bps' => 1150],
            'tax_cents' => 32453,
            'net_before_tax_cents' => 282200,
            'net_payable_cents' => 249747,
            'net_social_cents' => 268000,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => [
                ['code' => 'urssaf_maladie', 'label' => 'Maladie', 'category' => 'social', 'base_type' => 'deplafonne', 'base_cents' => 350000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 700, 'employee_cents' => 0, 'employer_cents' => 24500],
                ['code' => 'urssaf_vieillesse_plaf', 'label' => 'Vieillesse plaf.', 'category' => 'social', 'base_type' => 'plafonnee', 'base_cents' => 350000, 'employee_rate_bps' => 690, 'employer_rate_bps' => 855, 'employee_cents' => 24150, 'employer_cents' => 29925],
            ],
            'relief_lines' => ['total_employer_relief_cents' => 0, 'lines' => []],
            'blocking_anomalies' => [],
            'calculation_snapshot' => ['snapshot_version' => 'calc-snapshot-v3'],
            'calculated_at' => now(),
        ]);
        Model::reguard();

        $declaration2 = $useCase->execute($this->payrollRun, $this->user->id);

        $this->assertNotSame($hash1, $declaration2->payload_hash);
    }

    public function test_hash_is_sha256(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);

        // SHA-256 produces a 64-char hex string
        $this->assertSame(64, strlen($declaration->payload_hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $declaration->payload_hash);
    }

    public function test_hash_preserved_after_submission(): void
    {
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);
        $hashBeforeSubmit = $declaration->payload_hash;

        // Submit
        Model::unguard();
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $declaration->transitionTo(DsnDeclaration::STATUS_SUBMITTED);
        $declaration->submission_reference = 'REF-HASH';
        $declaration->submitted_by = $this->user->id;
        $declaration->submitted_at = now();
        $declaration->save();
        Model::reguard();

        // Reload and verify hash unchanged
        $fresh = DsnDeclaration::withoutGlobalScopes()->find($declaration->id);
        $this->assertSame($hashBeforeSubmit, $fresh->payload_hash);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private PayrollRun $payrollRun;
    private PayrollCalculation $calculation;

    private function createDeclaration(string $status): DsnDeclaration
    {
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => 'validated',
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'hardening:' . $status . ':' . uniqid(),
        ]);

        return DsnDeclaration::create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-04',
            'status' => $status,
            'payload_hash' => hash('sha256', 'test-' . $status),
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ]);
    }

    /**
     * Seed full payroll fixtures for use case tests (mirrors ExportPayrollDsnUseCaseTest).
     */
    private function seedFullPayrollFixtures(): void
    {
        Model::unguard();

        \App\Core\Fields\FieldDefinitionCatalog::sync();

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'email' => 'marie@hardening.test',
            'employee_number' => 'EMP-HARD-001',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ]);

        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2024-01-15',
            'is_current' => true,
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $contract->id,
            'base_salary_cents' => 300000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2024-01-15',
        ]);

        // Set employee field values for DSN resolution
        $fieldValues = [
            'social_security_number' => '2 85 12 75 108 042 29',
            'gender' => 'F',
            'birth_date' => '1985-12-15',
            'birth_city' => 'Paris',
            'birth_department' => '75',
            'birth_country' => 'FR',
            'nationality' => 'FR',
            'personal_address_street' => '15 rue de la Paix',
            'personal_address_postal_code' => '75001',
            'personal_address_city' => 'Paris',
            'personal_address_country_code' => 'FR',
        ];

        foreach ($fieldValues as $code => $value) {
            $fieldDef = FieldDefinition::whereNull('company_id')
                ->where('code', $code)
                ->first();

            if ($fieldDef) {
                FieldValue::create([
                    'field_definition_id' => $fieldDef->id,
                    'model_type' => 'App\\Core\\Models\\User',
                    'model_id' => $this->user->id,
                    'value' => ['value' => $value],
                ]);
            }
        }

        // PayrollRun
        $this->payrollRun = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => PayrollRun::STATUS_VALIDATED,
            'currency' => 'EUR',
            'employee_count' => 1,
            'total_worked_minutes' => 9100,
            'total_gross_cents' => 300000,
            'total_overtime_minutes' => 0,
            'total_leave_days_hundredths' => 0,
            'idempotency_key' => 'payroll:hardening:' . uniqid(),
        ]);

        $timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => TimesheetPeriod::STATUS_LOCKED,
            'total_worked_minutes' => 9100,
            'total_break_minutes' => 0,
            'total_overtime_minutes' => 0,
            'total_planned_minutes' => 9100,
            'total_leave_days_hundredths' => 0,
            'anomaly_count' => 0,
            'locked_at' => now(),
            'locked_by' => $this->user->id,
        ]);

        $line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->payrollRun->id,
            'employee_id' => $employee->id,
            'timesheet_period_id' => $timesheet->id,
            'worked_minutes' => 9100,
            'break_minutes' => 0,
            'daily_overtime_minutes' => 0,
            'weekly_overtime_minutes' => 0,
            'total_overtime_minutes' => 0,
            'planned_minutes' => 9100,
            'leave_days_hundredths' => 0,
            'paid_leave_days_hundredths' => 0,
            'unpaid_leave_days_hundredths' => 0,
            'leave_minutes' => 0,
            'base_salary_cents' => 300000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 300000,
            'gross_breakdown' => [
                'base_salary_cents' => 300000,
                'overtime_cents' => 0,
                'base_hours' => 151.67,
                'total_hours' => 151.67,
            ],
            'compensation_snapshot' => [
                'base_salary_cents' => 300000,
                'overtime_rate_bps' => 2500,
                'currency' => 'EUR',
                'pay_frequency' => 'monthly',
                'contract_id' => $contract->id,
                'weekly_hours' => 35,
                'benefits' => [],
            ],
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);

        $this->calculation = PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $line->id,
            'status' => PayrollCalculation::STATUS_VALIDATED,
            'rule_version' => 'payroll-calc-v2',
            'gross_total_cents' => 300000,
            'plafond_ss_monthly_cents' => 383400,
            'contributions_employee_cents' => 67800,
            'contributions_employer_cents' => 128700,
            'total_cost_employer_cents' => 428700,
            'taxable_income_cents' => 232200,
            'tax_breakdown' => ['tax_rate_bps' => 1150],
            'tax_cents' => 26703,
            'net_before_tax_cents' => 232200,
            'net_payable_cents' => 205497,
            'net_social_cents' => 218000,
            'benefits_cents' => 0,
            'deductions_cents' => 0,
            'contribution_lines' => [
                ['code' => 'urssaf_maladie', 'label' => 'Maladie', 'category' => 'social', 'base_type' => 'deplafonne', 'base_cents' => 300000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 700, 'employee_cents' => 0, 'employer_cents' => 21000],
                ['code' => 'urssaf_vieillesse_plaf', 'label' => 'Vieillesse plaf.', 'category' => 'social', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 690, 'employer_rate_bps' => 855, 'employee_cents' => 20700, 'employer_cents' => 25650],
                ['code' => 'urssaf_vieillesse_deplaf', 'label' => 'Vieillesse déplaf.', 'category' => 'social', 'base_type' => 'deplafonne', 'base_cents' => 300000, 'employee_rate_bps' => 40, 'employer_rate_bps' => 185, 'employee_cents' => 1200, 'employer_cents' => 5550],
                ['code' => 'allocations_familiales', 'label' => 'AF', 'category' => 'social', 'base_type' => 'deplafonne', 'base_cents' => 300000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 530, 'employee_cents' => 0, 'employer_cents' => 15900],
                ['code' => 'retraite_t1', 'label' => 'Retraite T1', 'category' => 'retraite', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 390, 'employer_rate_bps' => 630, 'employee_cents' => 11700, 'employer_cents' => 18900],
                ['code' => 'ceg_t1', 'label' => 'CEG T1', 'category' => 'retraite', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 85, 'employer_rate_bps' => 150, 'employee_cents' => 2550, 'employer_cents' => 4500],
                ['code' => 'chomage', 'label' => 'Chômage', 'category' => 'social', 'base_type' => 'plafonnee', 'base_cents' => 300000, 'employee_rate_bps' => 0, 'employer_rate_bps' => 420, 'employee_cents' => 0, 'employer_cents' => 12600],
                ['code' => 'csg_deductible', 'label' => 'CSG déductible', 'category' => 'csg', 'base_type' => 'csg', 'base_cents' => 294750, 'employee_rate_bps' => 681, 'employer_rate_bps' => 0, 'employee_cents' => 20073, 'employer_cents' => 0],
                ['code' => 'csg_non_deductible', 'label' => 'CSG non déductible', 'category' => 'csg', 'base_type' => 'csg', 'base_cents' => 294750, 'employee_rate_bps' => 240, 'employer_rate_bps' => 0, 'employee_cents' => 7074, 'employer_cents' => 0],
                ['code' => 'crds', 'label' => 'CRDS', 'category' => 'csg', 'base_type' => 'csg', 'base_cents' => 294750, 'employee_rate_bps' => 50, 'employer_rate_bps' => 0, 'employee_cents' => 1474, 'employer_cents' => 0],
            ],
            'relief_lines' => ['total_employer_relief_cents' => 0, 'lines' => []],
            'blocking_anomalies' => [],
            'calculation_snapshot' => ['snapshot_version' => 'calc-snapshot-v3'],
            'calculated_at' => now(),
        ]);

        app()->instance('company.context', $this->company);

        Model::reguard();
    }
}
