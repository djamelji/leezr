<?php

namespace Tests\Feature;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\ReadModels\DsnDeclarationReadModel;
use App\Modules\Workforce\UseCases\ExportPayrollDsnUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for ExportPayrollDsnUseCase + DsnDeclaration + DsnDeclarationReadModel.
 *
 * Sprint 6.4 — ADR-522
 */
class ExportPayrollDsnUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private EmploymentContract $contract;
    private PayrollRun $run;
    private PayrollLine $line;
    private PayrollCalculation $calculation;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Storage::fake('local');

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
            'name' => 'DSN Export Co',
            'slug' => 'dsn-export-co',
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

        $this->user = User::factory()->create();

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'email' => 'marie@test.com',
            'employee_number' => 'EMP-DSN-001',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ]);

        $this->contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'status' => EmploymentContract::STATUS_ACTIVE,
            'start_date' => '2024-01-15',
            'is_current' => true,
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $this->contract->id,
            'base_salary_cents' => 300000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'overtime_rate_bps' => 2500,
            'effective_from' => '2024-01-15',
        ]);

        // Sync field definitions so DSN codes exist
        \App\Core\Fields\FieldDefinitionCatalog::sync();

        // Set DSN fields for employee via EAV
        $this->setEmployeeFieldValues();

        // PayrollRun in validated status
        $this->run = PayrollRun::withoutGlobalScopes()->create([
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
            'idempotency_key' => 'payroll:dsn:test:unique',
        ]);

        $timesheet = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
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

        $this->line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->run->id,
            'employee_id' => $this->employee->id,
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
                'contract_id' => $this->contract->id,
                'weekly_hours' => 35,
                'benefits' => [],
            ],
            'timesheet_snapshot' => ['total_worked_minutes' => 9100],
        ]);

        $this->calculation = PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_line_id' => $this->line->id,
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
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        Model::reguard();
        parent::tearDown();
    }

    private function setEmployeeFieldValues(): void
    {
        $fields = [
            'social_security_number' => '2 85 12 75 108 042 29',
            'gender' => 'F',
            'birth_date' => '1985-12-15',
            'birth_city' => 'Paris',
            'birth_department' => '75',
            'birth_country' => 'FR',
            'nationality' => 'FR',
            'personal_address_street' => '10 rue de Rivoli',
            'personal_address_postal_code' => '75001',
            'personal_address_city' => 'Paris',
            'personal_address_country_code' => 'FR',
        ];

        foreach ($fields as $code => $value) {
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
    }

    // ═══════════════════════════════════════════════════════════════
    // Happy path
    // ═══════════════════════════════════════════════════════════════

    public function test_happy_path_generates_dsn_declaration(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        $this->assertInstanceOf(DsnDeclaration::class, $declaration);
        $this->assertSame($this->company->id, $declaration->company_id);
        $this->assertSame($this->run->id, $declaration->payroll_run_id);
        $this->assertSame('monthly', $declaration->declaration_type);
        $this->assertSame('2026-01', $declaration->period_month);
        $this->assertSame(DsnDeclaration::STATUS_VALIDATED, $declaration->status);
        $this->assertNotNull($declaration->payload_hash);
        $this->assertNotNull($declaration->file_path);
        $this->assertNull($declaration->validation_errors);
        $this->assertSame($this->user->id, $declaration->generated_by);
        $this->assertNotNull($declaration->generated_at);
    }

    public function test_happy_path_persists_file(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        Storage::disk('local')->assertExists($declaration->file_path);
        $content = Storage::disk('local')->get($declaration->file_path);
        $this->assertStringContainsString('S21.G00.06.', $content);
        $this->assertStringContainsString('S21.G00.30.', $content);
    }

    public function test_happy_path_payload_snapshot_stored(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        $snapshot = $declaration->payload_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertSame('mensuelle', $snapshot['declaration_type']);
        $this->assertSame($this->run->id, $snapshot['payroll_run_id']);
        $this->assertSame(1, $snapshot['employees_count']);
    }

    public function test_happy_path_creates_audit_log(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);

        $log = CompanyAuditLog::where('company_id', $this->company->id)
            ->where('action', 'dsn_declaration.generated')
            ->first();

        $this->assertNotNull($log, 'Audit log must exist');
        $this->assertSame('dsn_declaration', $log->target_type);
    }

    // ═══════════════════════════════════════════════════════════════
    // Guards
    // ═══════════════════════════════════════════════════════════════

    public function test_blocks_draft_status(): void
    {
        $this->run->status = PayrollRun::STATUS_DRAFT;
        $this->run->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'validated' or 'exported'");

        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);
    }

    public function test_blocks_computed_status(): void
    {
        $this->run->status = PayrollRun::STATUS_COMPUTED;
        $this->run->saveQuietly();

        $this->expectException(\DomainException::class);

        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);
    }

    public function test_allows_exported_status(): void
    {
        $this->run->status = PayrollRun::STATUS_EXPORTED;
        $this->run->saveQuietly();

        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        $this->assertInstanceOf(DsnDeclaration::class, $declaration);
    }

    public function test_blocks_without_validated_calculation(): void
    {
        // Change calculation status to 'calculated' (not validated)
        \Illuminate\Support\Facades\DB::table('workforce_payroll_calculations')
            ->where('id', $this->calculation->id)
            ->update(['status' => PayrollCalculation::STATUS_CALCULATED]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no validated calculation');

        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);
    }

    // ═══════════════════════════════════════════════════════════════
    // Idempotency
    // ═══════════════════════════════════════════════════════════════

    public function test_idempotent_same_run_returns_existing(): void
    {
        $useCase = new ExportPayrollDsnUseCase();

        $first = $useCase->execute($this->run, $this->user->id);
        $second = $useCase->execute($this->run, $this->user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DsnDeclaration::withoutGlobalScopes()->count());
    }

    public function test_idempotent_exported_declaration_returned_as_is(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        // Mark as exported
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        // Re-execute: should return existing without re-generating
        $same = $useCase->execute($this->run, $this->user->id);
        $this->assertSame($declaration->id, $same->id);
        $this->assertSame(DsnDeclaration::STATUS_EXPORTED, $same->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // Hash stability
    // ═══════════════════════════════════════════════════════════════

    public function test_payload_hash_is_sha256(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        $this->assertNotNull($declaration->payload_hash);
        $this->assertSame(64, strlen($declaration->payload_hash)); // SHA-256 = 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $declaration->payload_hash);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multi-tenant isolation
    // ═══════════════════════════════════════════════════════════════

    public function test_multi_tenant_isolation(): void
    {
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'jobdomain_key' => 'tech',
            'market_key' => 'FR',
            'siret' => '41816609600069',
            'naf_code' => '6201Z',
            'address_street' => '1 avenue Foch',
            'address_postal_code' => '75016',
            'address_city' => 'Paris',
        ]);

        // Generate DSN for first company
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        // Verify no cross-company leakage
        $otherDeclarations = DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $otherCompany->id)
            ->count();
        $this->assertSame(0, $otherDeclarations);

        // Verify declaration is scoped
        $this->assertSame($this->company->id, $declaration->company_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // No payroll mutation
    // ═══════════════════════════════════════════════════════════════

    public function test_payroll_run_not_mutated(): void
    {
        $originalStatus = $this->run->status;
        $originalGross = $this->run->total_gross_cents;

        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);

        $this->run->refresh();
        $this->assertSame($originalStatus, $this->run->status);
        $this->assertSame($originalGross, $this->run->total_gross_cents);
    }

    public function test_payroll_calculation_not_mutated(): void
    {
        $originalNet = $this->calculation->net_payable_cents;

        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);

        $this->calculation->refresh();
        $this->assertSame($originalNet, $this->calculation->net_payable_cents);
    }

    // ═══════════════════════════════════════════════════════════════
    // Invalid payload blocked
    // ═══════════════════════════════════════════════════════════════

    public function test_missing_employer_identity_blocks_before_any_file_or_declaration(): void
    {
        // ADR-784 : an incomplete employer identity is no longer saved as a draft file — nothing is
        // produced, the error names the fields (it used to persist an invalid draft).
        $this->company->siret = null;
        $this->company->saveQuietly();

        try {
            (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
            $this->fail('Expected DsnEmployerIdentityException');
        } catch (\App\Core\Workforce\Dsn\DsnEmployerIdentityException $e) {
            $this->assertSame('missing', $e->issues['siret'] ?? null);
        }

        $this->assertNull(DsnDeclaration::withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->first(), 'no declaration');
    }

    // ── ADR-784 : identité employeur canonique (profil Company, colonnes en repli) ──────────────────

    private function profile(\App\Core\Models\Company $company, array $values): void
    {
        foreach ($values as $code => $value) {
            $def = \App\Core\Fields\FieldDefinition::whereNull('company_id')->where('code', $code)->firstOrFail();
            \App\Core\Fields\FieldValue::updateOrCreate(['field_definition_id' => $def->id, 'model_type' => 'company', 'model_id' => $company->id], ['value' => $value]);
        }
    }

    public function test_profile_identity_is_written_in_the_dsn_file(): void
    {
        $this->profile($this->company, ['siret' => '44210000000003', 'company_address' => '8 rue du Profil', 'company_postal_code' => '69003', 'company_city' => 'Lyon']);

        $declaration = (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
        $content = Storage::disk('local')->get($declaration->file_path);
        $this->assertSame('44210000000003', $declaration->payload_snapshot['company']['siret']);
        $this->assertStringContainsString('8 rue du Profil', $content);
        $this->assertStringContainsString('69003', $content);
        $this->assertStringContainsString("S21.G00.06.001,'00003'", $content, 'NIC from the profile SIRET');
        $this->assertStringContainsString('Lyon', $content);
        $this->assertStringNotContainsString('15 rue de la Paix', $content, 'legacy column not used when the profile is filled');
    }

    public function test_historical_columns_still_produce_a_valid_dsn(): void
    {
        $declaration = (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
        $this->assertSame('73282932000074', $declaration->payload_snapshot['company']['siret']);
        $this->assertStringContainsString('Paris', Storage::disk('local')->get($declaration->file_path));
    }

    public function test_incomplete_identity_lists_fields_and_writes_nothing(): void
    {
        $this->company->forceFill(['address_street' => null, 'address_postal_code' => null, 'address_city' => null])->saveQuietly();
        $before = count(Storage::disk('local')->allFiles());

        try {
            (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
            $this->fail('Expected DsnEmployerIdentityException');
        } catch (\App\Core\Workforce\Dsn\DsnEmployerIdentityException $e) {
            $this->assertSame(['address_street' => 'missing', 'address_postal_code' => 'missing', 'address_city' => 'missing'], $e->issues);
        }
        $this->assertSame($before, count(Storage::disk('local')->allFiles()), 'no file');
        $this->assertNull(DsnDeclaration::withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->first(), 'no declaration');
    }

    public function test_invalid_siret_and_naf_are_refused(): void
    {
        $this->profile($this->company, ['siret' => '12345678901234']); // fails Luhn
        $this->company->forceFill(['naf_code' => 'XX'])->saveQuietly();

        try {
            (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
            $this->fail('Expected DsnEmployerIdentityException');
        } catch (\App\Core\Workforce\Dsn\DsnEmployerIdentityException $e) {
            $this->assertSame('invalid', $e->issues['siret']);
            $this->assertSame('invalid', $e->issues['naf_code']);
        }
        $this->assertNull(DsnDeclaration::withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->first());
    }

    /** ADR-785 — the NAF entered in the profile feeds the DSN, even without any historical column. */
    public function test_naf_from_profile_is_used_when_the_column_is_empty(): void
    {
        $this->company->forceFill(['naf_code' => null])->saveQuietly();
        $this->profile($this->company, ['naf_code' => '49.41a']);

        $declaration = (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
        $this->assertSame('4941A', $declaration->payload_snapshot['company']['naf_code']);
        $this->assertStringContainsString('4941A', Storage::disk('local')->get($declaration->file_path));
    }

    public function test_profile_naf_wins_over_the_historical_column(): void
    {
        $this->profile($this->company, ['naf_code' => '4941A']);
        $declaration = (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
        $this->assertSame('4941A', $declaration->payload_snapshot['company']['naf_code']);
    }

    public function test_missing_naf_everywhere_blocks_the_export(): void
    {
        $this->company->forceFill(['naf_code' => null])->saveQuietly();
        try {
            (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
            $this->fail('Expected DsnEmployerIdentityException');
        } catch (\App\Core\Workforce\Dsn\DsnEmployerIdentityException $e) {
            $this->assertSame('missing', $e->issues['naf_code']);
        }
        $this->assertNull(DsnDeclaration::withoutGlobalScopes()->where('payroll_run_id', $this->run->id)->first());
    }

    public function test_another_company_profile_never_feeds_this_dsn(): void
    {
        $other = \App\Core\Models\Company::create(['name' => 'Other DSN', 'slug' => 'other-dsn-'.uniqid(), 'jobdomain_key' => $this->company->jobdomain_key, 'market_key' => 'FR']);
        $this->profile($other, ['siret' => '44210000000003', 'company_city' => 'Nice']);

        $declaration = (new ExportPayrollDsnUseCase())->execute($this->run, $this->user->id);
        $this->assertSame('73282932000074', $declaration->payload_snapshot['company']['siret']);
        $this->assertStringNotContainsString('Nice', Storage::disk('local')->get($declaration->file_path));
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnDeclaration model
    // ═══════════════════════════════════════════════════════════════

    public function test_model_immutability_after_submitted(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        // Transition to exported then submitted
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
        $declaration->exported_by = $this->user->id;
        $declaration->exported_at = now();
        $declaration->save();

        $declaration->transitionTo(DsnDeclaration::STATUS_SUBMITTED);
        $declaration->submission_reference = 'TEST-REF';
        $declaration->submitted_by = $this->user->id;
        $declaration->submitted_at = now();
        $declaration->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $declaration->period_month = '2026-02';
        $declaration->save();
    }

    public function test_model_state_machine(): void
    {
        $declaration = DsnDeclaration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-01',
            'status' => DsnDeclaration::STATUS_DRAFT,
        ]);

        $this->assertTrue($declaration->canTransitionTo(DsnDeclaration::STATUS_VALIDATED));
        $this->assertFalse($declaration->canTransitionTo(DsnDeclaration::STATUS_EXPORTED));

        $declaration->transitionTo(DsnDeclaration::STATUS_VALIDATED);
        $this->assertSame(DsnDeclaration::STATUS_VALIDATED, $declaration->status);

        $this->assertTrue($declaration->canTransitionTo(DsnDeclaration::STATUS_EXPORTED));
        $this->assertFalse($declaration->canTransitionTo(DsnDeclaration::STATUS_DRAFT));
    }

    public function test_model_state_machine_invalid_transition(): void
    {
        $declaration = DsnDeclaration::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->run->id,
            'declaration_type' => 'monthly',
            'period_month' => '2026-01',
            'status' => DsnDeclaration::STATUS_DRAFT,
        ]);

        $this->expectException(\DomainException::class);
        $declaration->transitionTo(DsnDeclaration::STATUS_EXPORTED);
    }

    // ═══════════════════════════════════════════════════════════════
    // DsnDeclarationReadModel
    // ═══════════════════════════════════════════════════════════════

    public function test_read_model_for_company(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);

        $paginated = DsnDeclarationReadModel::forCompany($this->company->id);
        $this->assertSame(1, $paginated->total());
        $this->assertSame('2026-01', $paginated->items()[0]->period_month);
    }

    public function test_read_model_for_payroll_run(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);

        $declaration = DsnDeclarationReadModel::forPayrollRun($this->run->id);
        $this->assertNotNull($declaration);
        $this->assertSame($this->run->id, $declaration->payroll_run_id);
    }

    public function test_read_model_latest_for_period(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $useCase->execute($this->run, $this->user->id);

        $declaration = DsnDeclarationReadModel::latestForPeriod($this->company->id, '2026-01');
        $this->assertNotNull($declaration);
        $this->assertSame('2026-01', $declaration->period_month);
    }

    public function test_read_model_returns_null_for_unknown(): void
    {
        $this->assertNull(DsnDeclarationReadModel::forPayrollRun(99999));
        $this->assertNull(DsnDeclarationReadModel::latestForPeriod($this->company->id, '2099-12'));
    }

    // ═══════════════════════════════════════════════════════════════
    // DSN content verification
    // ═══════════════════════════════════════════════════════════════

    public function test_dsn_file_contains_all_blocs(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        $content = Storage::disk('local')->get($declaration->file_path);

        // Establishment
        $this->assertStringContainsString('S21.G00.06.', $content);
        // CTP aggregates
        $this->assertStringContainsString('S21.G00.22.', $content);
        // Employee identity
        $this->assertStringContainsString('S21.G00.30.', $content);
        // Contract
        $this->assertStringContainsString('S21.G00.40.', $content);
        // Payment
        $this->assertStringContainsString('S21.G00.50.', $content);
        // Remuneration
        $this->assertStringContainsString('S21.G00.51.', $content);
        // CSG/CRDS
        $this->assertStringContainsString('S21.G00.78.', $content);
        // Individual contributions
        $this->assertStringContainsString('S21.G00.81.', $content);
    }

    public function test_dsn_file_contains_employee_data(): void
    {
        $useCase = new ExportPayrollDsnUseCase();
        $declaration = $useCase->execute($this->run, $this->user->id);

        $content = Storage::disk('local')->get($declaration->file_path);

        // Employee name
        $this->assertStringContainsString("'DUPONT'", $content);
        $this->assertStringContainsString("'Marie'", $content);
        // NIR
        $this->assertStringContainsString('285127510804229', $content);
        // Company NIC
        $this->assertStringContainsString("'00074'", $content);
    }
}
