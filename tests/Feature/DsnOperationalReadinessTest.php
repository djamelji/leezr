<?php

namespace Tests\Feature;

use App\Core\Audit\AuditLogger;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\ReadModels\DsnAuditReadModel;
use App\Modules\Workforce\ReadModels\DsnDeclarationReadModel;
use App\Modules\Workforce\UseCases\ExportPayrollDsnUseCase;
use App\Modules\Workforce\UseCases\SubmitDsnDeclarationUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sprint 6.7 — DSN Operational Readiness.
 *
 * Tests: ReadModels enrichis, DsnAuditReadModel, CLI commands,
 * inspect payload, validation summary, rejected regeneration trace,
 * accepted immutable.
 *
 * ADR-525
 */
class DsnOperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PayrollRun $payrollRun;
    private PayrollCalculation $calculation;

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
            'name' => 'OpsReady Co',
            'slug' => 'opsready-co',
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
            'email' => 'operator@opsready.test',
            'password' => bcrypt('password'),
        ]);

        Model::reguard();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════
    // 1. DsnDeclarationReadModel enriched
    // ═══════════════════════════════════════════════════════════════

    public function test_failed_and_rejected_dashboard(): void
    {
        Model::unguard();
        $this->createDeclaration(['status' => 'draft', 'validation_errors' => [['severity' => 'error', 'message' => 'bad siret']]]);
        $this->createDeclaration(['status' => 'rejected', 'period_month' => '2026-02']);
        $this->createDeclaration(['status' => 'exported', 'period_month' => '2026-03']); // should NOT appear
        $this->createDeclaration(['status' => 'draft', 'period_month' => '2026-04']); // no errors → should NOT appear
        Model::reguard();

        $result = DsnDeclarationReadModel::failedAndRejected($this->company->id);

        $this->assertCount(2, $result);
        $statuses = $result->pluck('status')->toArray();
        $this->assertContains('draft', $statuses);
        $this->assertContains('rejected', $statuses);
    }

    public function test_latest_by_company_period(): void
    {
        Model::unguard();
        $this->createDeclaration(['period_month' => '2026-01', 'generated_at' => '2026-01-15 10:00:00']);
        $this->createDeclaration(['period_month' => '2026-01', 'generated_at' => '2026-01-20 10:00:00']);
        $this->createDeclaration(['period_month' => '2026-02', 'generated_at' => '2026-02-15 10:00:00']);
        Model::reguard();

        $result = DsnDeclarationReadModel::latestByCompanyPeriod($this->company->id);

        $this->assertCount(2, $result);
        $periods = $result->pluck('period_month')->toArray();
        $this->assertContains('2026-01', $periods);
        $this->assertContains('2026-02', $periods);
    }

    public function test_validation_summary_with_mixed_entries(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration([
            'validation_errors' => [
                ['severity' => 'error', 'category' => 'siret', 'rubrique' => 'S21.G00.06.001', 'message' => 'SIRET fails Luhn'],
                ['severity' => 'warning', 'category' => 'encoding', 'rubrique' => 'S21.G00.30.002', 'message' => 'Non-ISO char', 'employee_id' => 42],
                ['severity' => 'error', 'category' => 'nir', 'rubrique' => 'S21.G00.30.001', 'message' => 'NIR fails mod97', 'employee_id' => 42],
            ],
        ]);
        Model::reguard();

        $summary = DsnDeclarationReadModel::validationSummary($declaration->id);

        $this->assertTrue($summary['found']);
        $this->assertEquals(2, $summary['error_count']);
        $this->assertEquals(1, $summary['warning_count']);
        $this->assertEquals(3, $summary['total_entries']);
        $this->assertEquals(['siret' => 1, 'encoding' => 1, 'nir' => 1], $summary['by_category']);
        $this->assertEquals([42 => 2], $summary['by_employee']);
    }

    public function test_validation_summary_not_found(): void
    {
        $summary = DsnDeclarationReadModel::validationSummary(99999);

        $this->assertFalse($summary['found']);
    }

    public function test_statistics(): void
    {
        Model::unguard();
        $this->createDeclaration(['status' => 'draft']);
        $this->createDeclaration(['status' => 'exported', 'period_month' => '2026-02']);
        $this->createDeclaration(['status' => 'exported', 'period_month' => '2026-03']);
        $this->createDeclaration(['status' => 'accepted', 'period_month' => '2026-04']);
        Model::reguard();

        $stats = DsnDeclarationReadModel::statistics($this->company->id);

        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(1, $stats['by_status']['draft']);
        $this->assertEquals(2, $stats['by_status']['exported']);
        $this->assertEquals(1, $stats['by_status']['accepted']);
        $this->assertEquals('2026-04', $stats['latest_period']);
    }

    public function test_detail_loads_all_relations(): void
    {
        Model::unguard();
        $this->seedFullPayrollFixtures();
        Storage::fake('local');

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);
        Model::reguard();

        $detail = DsnDeclarationReadModel::detail($declaration->id);

        $this->assertNotNull($detail);
        $this->assertEquals($declaration->id, $detail->id);
        $this->assertNotNull($detail->payrollRun);
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. DsnAuditReadModel
    // ═══════════════════════════════════════════════════════════════

    public function test_audit_history_for_declaration(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration();

        // Create audit entries
        CompanyAuditLog::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'dsn_declaration.generated',
            'target_type' => 'dsn_declaration',
            'target_id' => (string) $declaration->id,
            'severity' => 'info',
            'metadata' => ['category' => 'workforce.dsn', 'payload_hash' => 'abc123'],
            'created_at' => now()->subMinutes(10),
        ]);
        CompanyAuditLog::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'dsn_declaration.submitted',
            'target_type' => 'dsn_declaration',
            'target_id' => (string) $declaration->id,
            'severity' => 'info',
            'metadata' => ['category' => 'workforce.dsn', 'submission_reference' => 'REF-001'],
            'created_at' => now()->subMinutes(5),
        ]);
        Model::reguard();

        $history = DsnAuditReadModel::historyForDeclaration($declaration->id);

        $this->assertCount(2, $history);
        $this->assertEquals('dsn_declaration.generated', $history[0]['action']);
        $this->assertEquals('dsn_declaration.submitted', $history[1]['action']);
    }

    public function test_payload_hash_timeline(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration();

        CompanyAuditLog::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'dsn_declaration.generated',
            'target_type' => 'dsn_declaration',
            'target_id' => (string) $declaration->id,
            'severity' => 'info',
            'metadata' => ['payload_hash' => 'hash_v1', 'status' => 'exported', 'period_month' => '2026-01'],
            'created_at' => now()->subMinutes(10),
        ]);
        CompanyAuditLog::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'dsn_declaration.submitted',
            'target_type' => 'dsn_declaration',
            'target_id' => (string) $declaration->id,
            'severity' => 'info',
            'metadata' => ['payload_hash' => 'hash_v1', 'period_month' => '2026-01'],
            'created_at' => now()->subMinutes(5),
        ]);
        Model::reguard();

        $timeline = DsnAuditReadModel::payloadHashTimeline($declaration->id);

        $this->assertCount(2, $timeline);
        $this->assertEquals('hash_v1', $timeline[0]['payload_hash']);
        $this->assertEquals('hash_v1', $timeline[1]['payload_hash']);
        $this->assertEquals('dsn_declaration.generated', $timeline[0]['action']);
        $this->assertEquals('dsn_declaration.submitted', $timeline[1]['action']);
    }

    public function test_audit_action_summary(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration();

        CompanyAuditLog::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'dsn_declaration.generated',
            'target_type' => 'dsn_declaration',
            'target_id' => (string) $declaration->id,
            'severity' => 'info',
            'metadata' => [],
            'created_at' => now()->subMinutes(10),
        ]);
        CompanyAuditLog::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->user->id,
            'actor_type' => 'user',
            'action' => 'dsn_declaration.generated',
            'target_type' => 'dsn_declaration',
            'target_id' => (string) $declaration->id,
            'severity' => 'info',
            'metadata' => [],
            'created_at' => now()->subMinutes(5),
        ]);
        Model::reguard();

        $summary = DsnAuditReadModel::actionSummary($this->company->id);

        $this->assertEquals(2, $summary['total']);
        $this->assertEquals(2, $summary['by_action']['dsn_declaration.generated']);
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. CLI Commands
    // ═══════════════════════════════════════════════════════════════

    public function test_cli_validate_shows_errors(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration([
            'validation_errors' => [
                ['severity' => 'error', 'category' => 'siret', 'rubrique' => 'S21.G00.06.001', 'message' => 'SIRET fails Luhn'],
                ['severity' => 'warning', 'category' => 'encoding', 'rubrique' => 'S21.G00.30.002', 'message' => 'Non-ISO char'],
            ],
        ]);
        Model::reguard();

        $this->artisan("dsn:validate {$declaration->id}")
            ->expectsOutputToContain('Errors:   1')
            ->expectsOutputToContain('Warnings: 1')
            ->assertExitCode(1); // FAILURE because errors > 0
    }

    public function test_cli_validate_clean_declaration(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration(['validation_errors' => null]);
        Model::reguard();

        $this->artisan("dsn:validate {$declaration->id}")
            ->expectsOutputToContain('No validation issues')
            ->assertExitCode(0);
    }

    public function test_cli_validate_not_found(): void
    {
        $this->artisan('dsn:validate 99999')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_cli_inspect_shows_declaration(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration([
            'payload_hash' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
        ]);
        Model::reguard();

        $this->artisan("dsn:inspect {$declaration->id}")
            ->expectsOutputToContain('DSN Declaration')
            ->expectsOutputToContain('2026-01')
            ->assertExitCode(0);
    }

    public function test_cli_inspect_not_found(): void
    {
        $this->artisan('dsn:inspect 99999')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_cli_regenerate_dry_run(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration(['status' => 'exported']);
        Model::reguard();

        $this->artisan("dsn:regenerate {$declaration->id} --dry-run")
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);
    }

    public function test_cli_regenerate_blocked_for_submitted(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration(['status' => 'submitted']);
        Model::reguard();

        $this->artisan("dsn:regenerate {$declaration->id}")
            ->expectsOutputToContain('Cannot regenerate')
            ->assertExitCode(1);
    }

    public function test_cli_regenerate_not_found(): void
    {
        $this->artisan('dsn:regenerate 99999')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. Support/debug: integration tests
    // ═══════════════════════════════════════════════════════════════

    public function test_inspect_payload_snapshot(): void
    {
        Model::unguard();
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);
        Model::reguard();

        // Payload snapshot should be stored
        $this->assertNotNull($declaration->payload_snapshot);
        $this->assertIsArray($declaration->payload_snapshot);
        $this->assertArrayHasKey('company', $declaration->payload_snapshot);
        $this->assertArrayHasKey('employees', $declaration->payload_snapshot);
    }

    public function test_validation_summary_for_exported_declaration(): void
    {
        Model::unguard();
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->payrollRun, $this->user->id);
        Model::reguard();

        $summary = DsnDeclarationReadModel::validationSummary($declaration->id);

        $this->assertTrue($summary['found']);
        $this->assertEquals('validated', $summary['status']);
        $this->assertNotNull($summary['payload_hash']);
    }

    public function test_rejected_regeneration_trace(): void
    {
        Model::unguard();
        Storage::fake('local');
        $this->seedFullPayrollFixtures();

        // Export → validated → exported → submitted → rejected → regenerate
        $exportUC = new ExportPayrollDsnUseCase();
        $declaration = $exportUC->execute($this->payrollRun, $this->user->id);
        $originalHash = $declaration->payload_hash;

        // Transition validated → exported
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Transition exported → submitted
        $declaration->transitionTo(DsnDeclaration::STATUS_SUBMITTED);
        $declaration->submission_reference = 'REF-TEST-001';
        $declaration->submitted_by = $this->user->id;
        $declaration->submitted_at = now();
        $declaration->save();

        // Transition submitted → rejected
        $declaration->transitionTo(DsnDeclaration::STATUS_REJECTED);
        $declaration->save();

        $this->assertEquals('rejected', $declaration->fresh()->status);
        $this->assertTrue($declaration->canRegenerate());

        // Regenerate should succeed — old declaration deleted, new one created
        $newDeclaration = $exportUC->execute($this->payrollRun, $this->user->id);

        $this->assertNotEquals($declaration->id, $newDeclaration->id);
        $this->assertEquals('validated', $newDeclaration->status);
        $this->assertNotNull($newDeclaration->payload_hash);
        Model::reguard();
    }

    public function test_accepted_immutable(): void
    {
        Model::unguard();
        $declaration = $this->createDeclaration(['status' => 'submitted']);

        // Transition to accepted
        $declaration->transitionTo(DsnDeclaration::STATUS_ACCEPTED);
        $declaration->save();
        Model::reguard();

        $this->assertEquals('accepted', $declaration->fresh()->status);
        $this->assertTrue($declaration->isTerminal());
        $this->assertFalse($declaration->canRegenerate());

        // Any update should throw
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $declaration->file_path = '/some/other/path.dsn';
        $declaration->save();
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createDeclaration(array $overrides = []): DsnDeclaration
    {
        static $runCounter = 0;
        $runCounter++;

        $run = PayrollRun::withoutGlobalScopes()->create([
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
            'idempotency_key' => 'payroll:ops:' . $runCounter . ':' . uniqid(),
        ]);

        return DsnDeclaration::withoutGlobalScopes()->create(array_merge([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-01',
            'status' => 'draft',
            'payload_hash' => null,
            'file_path' => null,
            'validation_errors' => null,
            'payload_snapshot' => null,
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ], $overrides));
    }

    private function seedFullPayrollFixtures(): void
    {
        Model::unguard();

        \App\Core\Fields\FieldDefinitionCatalog::sync();

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'email' => 'marie@opsready.test',
            'employee_number' => 'EMP-OPS-001',
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
            'idempotency_key' => 'payroll:ops-full:' . uniqid(),
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
