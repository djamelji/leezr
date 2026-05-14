<?php

namespace Tests\Feature;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\MemberDocument;
use App\Core\Workforce\CompensationPlan;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Core\Workforce\TimesheetPeriod;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Modules\Workforce\UseCases\ComputePayrollCalculationsUseCase;
use App\Modules\Workforce\UseCases\GenerateOfficialPayslipsUseCase;
use App\Modules\Workforce\UseCases\ValidatePayrollUseCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialPayslipUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private PayrollRun $run;
    private PayrollLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        // Market (FK for MarketRuleSet)
        \App\Core\Markets\Market::create([
            'key' => 'FR',
            'name' => 'France',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'dial_code' => '+33',
            'flag_code' => 'fr',
            'flag_svg' => '🇫🇷',
            'vat_rate_bps' => 2000,
        ]);

        $this->user = User::factory()->create();

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'TestCo Officiel',
            'slug' => 'testco-officiel',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'tech',
            'siret' => '12345678901234',
            'naf_code' => '6201Z',
        ]);

        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Marie',
            'last_name' => 'Martin',
            'employee_number' => 'EMP-OFF-001',
            'email' => 'marie@test.com',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ]);

        $contract = EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'contract_type' => 'cdi',
            'work_model_key' => 'horaire',
            'weekly_hours' => 35,
            'start_date' => '2024-01-15',
            'status' => EmploymentContract::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        CompensationPlan::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'contract_id' => $contract->id,
            'base_salary_cents' => 350000,
            'currency' => 'EUR',
            'pay_frequency' => 'monthly',
            'effective_from' => '2024-01-15',
        ]);

        $tp = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
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

        $this->run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-30',
            'status' => PayrollRun::STATUS_COMPUTED,
            'currency' => 'EUR',
            'employee_count' => 1,
        ]);

        $this->line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $this->run->id,
            'employee_id' => $employee->id,
            'timesheet_period_id' => $tp->id,
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
            'base_salary_cents' => 350000,
            'overtime_rate_bps' => 2500,
            'gross_basis_cents' => 350000,
            'gross_breakdown' => [
                'base_salary_cents' => 350000,
                'overtime_cents' => 0,
                'unpaid_leave_deduction_cents' => 0,
                'gross_basis_cents' => 350000,
            ],
            'compensation_snapshot' => [
                'base_salary_cents' => 350000,
                'overtime_rate_bps' => 2500,
                'currency' => 'EUR',
                'pay_frequency' => 'monthly',
                'benefits' => [],
            ],
            'timesheet_snapshot' => [
                'total_worked_minutes' => 9100,
            ],
        ]);

        // Seed payroll rules + official template
        $this->seed(\Database\Seeders\WorkforcePayrollRuleSeeder::class);
        $this->seed(\Database\Seeders\PayslipOfficialFrTemplateSeeder::class);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('company.context');
        parent::tearDown();
    }

    private function computeAndValidate(): void
    {
        app(ComputePayrollCalculationsUseCase::class)->execute($this->run, $this->user->id);
        $this->run->refresh();
        app(ValidatePayrollUseCase::class)->execute($this->run, $this->user->id);
        $this->run->refresh();
    }

    // --- T1: Happy path — generates official payslips for all lines ---

    /** @test */
    public function generate_official_payslips_happy_path()
    {
        $this->computeAndValidate();

        $docs = app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);

        $this->assertCount(1, $docs);
        $this->assertInstanceOf(GeneratedDocument::class, $docs[0]);
        $this->assertEquals('payroll_line', $docs[0]->subject_type);
        $this->assertEquals($this->line->id, $docs[0]->subject_id);
        $this->assertEquals($this->company->id, $docs[0]->company_id);
    }

    // --- T2: Guard — blocks non-validated run ---

    /** @test */
    public function blocks_non_validated_run()
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("expected 'validated'");

        app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
    }

    // --- T3: Guard — blocks missing SIRET ---

    /** @test */
    public function blocks_missing_siret()
    {
        $this->computeAndValidate();

        // Remove SIRET
        $this->company->siret = null;
        $this->company->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('SIRET');

        app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
    }

    // --- T4: Guard — blocks missing NAF code ---

    /** @test */
    public function blocks_missing_naf_code()
    {
        $this->computeAndValidate();

        // Remove NAF code
        $this->company->naf_code = null;
        $this->company->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('NAF');

        app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
    }

    // --- T5: Guard — blocks with blocking anomalies ---

    /** @test */
    public function blocks_with_blocking_anomalies()
    {
        app(ComputePayrollCalculationsUseCase::class)->execute($this->run, $this->user->id);

        // Manually set blocking anomalies + validated status
        $calc = PayrollCalculation::withoutGlobalScopes()
            ->where('payroll_line_id', $this->line->id)
            ->first();
        $calc->blocking_anomalies = [['type' => 'negative_net', 'severity' => 'blocking']];
        $calc->status = PayrollCalculation::STATUS_VALIDATED;
        $calc->save();

        $this->run->status = PayrollRun::STATUS_VALIDATED;
        $this->run->validated_by = $this->user->id;
        $this->run->validated_at = now();
        $this->run->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('blocking anomalies');

        app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
    }

    // --- T6: Idempotency — re-run returns existing, single document in DB ---

    /** @test */
    public function idempotent_generation()
    {
        $this->computeAndValidate();

        $docs1 = app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
        $docs2 = app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);

        $this->assertCount(1, $docs1);
        $this->assertCount(1, $docs2);
        $this->assertEquals($docs1[0]->id, $docs2[0]->id);

        // Only 1 official document in DB for this line
        $count = GeneratedDocument::withoutGlobalScopes()
            ->where('subject_type', 'payroll_line')
            ->where('subject_id', $this->line->id)
            ->whereJsonContains('metadata->official', true)
            ->count();
        $this->assertEquals(1, $count);
    }

    // --- T7: Vault — MemberDocument created for payroll_line subject ---

    /** @test */
    public function vault_member_document_created()
    {
        $this->computeAndValidate();

        $docs = app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);

        $doc = $docs[0];
        $this->assertNotNull($doc->member_document_id, 'Official payslip must have a MemberDocument vault entry');

        // Verify MemberDocument exists and belongs to the correct user
        $memberDoc = MemberDocument::withoutGlobalScopes()->find($doc->member_document_id);
        $this->assertNotNull($memberDoc);
        $this->assertEquals($this->company->id, $memberDoc->company_id);
        $this->assertEquals($this->user->id, $memberDoc->user_id);
        $this->assertEquals('application/pdf', $memberDoc->mime_type);
    }

    // --- T8: Snapshot — generation_snapshot contains calculation + meta data ---

    /** @test */
    public function snapshot_contains_full_data()
    {
        $this->computeAndValidate();

        $docs = app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
        $snapshot = $docs[0]->generation_snapshot;

        // Calculation data
        $this->assertArrayHasKey('calculation.gross_total_formatted', $snapshot);
        $this->assertArrayHasKey('calculation.net_payable_formatted', $snapshot);
        $this->assertArrayHasKey('calculation.net_social_formatted', $snapshot);
        $this->assertNotEmpty($snapshot['calculation.gross_total_formatted']);

        // Company data
        $this->assertArrayHasKey('company.siret', $snapshot);
        $this->assertArrayHasKey('company.naf_code', $snapshot);
        $this->assertEquals('12345678901234', $snapshot['company.siret']);
        $this->assertEquals('6201Z', $snapshot['company.naf_code']);

        // Meta
        $this->assertArrayHasKey('_meta', $snapshot);
        $this->assertEquals('payslip_official_fr', $snapshot['_meta']['template_code']);
    }

    // --- T9: Non-deletable — official document cannot be deleted ---

    /** @test */
    public function official_document_is_non_deletable()
    {
        $this->computeAndValidate();

        $docs = app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
        $doc = $docs[0];

        // Verify metadata
        $this->assertTrue($doc->metadata['official'] ?? false);
        $this->assertTrue($doc->metadata['non_deletable'] ?? false);

        // Attempt to delete should throw
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete an official document');

        $doc->delete();
    }

    // --- T10: Multi-tenant isolation ---

    /** @test */
    public function multi_tenant_isolation()
    {
        $this->computeAndValidate();

        // Generate for company A
        $docsA = app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
        $this->assertCount(1, $docsA);

        // Company B has no official payslips
        $companyB = Company::withoutGlobalScopes()->create([
            'name' => 'OtherCo',
            'slug' => 'otherco',
            'market_key' => 'FR',
            'currency' => 'EUR',
            'jobdomain_key' => 'finance',
            'siret' => '98765432109876',
            'naf_code' => '7010Z',
        ]);

        $docsB = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyB->id)
            ->whereJsonContains('metadata->official', true)
            ->count();
        $this->assertEquals(0, $docsB);
    }

    // --- T11: Guard — blocks if non-validated calculations ---

    /** @test */
    public function blocks_non_validated_calculations()
    {
        // Compute but don't validate
        app(ComputePayrollCalculationsUseCase::class)->execute($this->run, $this->user->id);

        // Force run to validated status but leave calculations as 'calculated'
        $this->run->status = PayrollRun::STATUS_VALIDATED;
        $this->run->validated_by = $this->user->id;
        $this->run->validated_at = now();
        $this->run->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not validated');

        app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
    }

    // --- T12: Guard — blocks if lines have no calculation ---

    /** @test */
    public function blocks_lines_without_calculation()
    {
        // Force run validated but line has no calculation
        $this->run->status = PayrollRun::STATUS_VALIDATED;
        $this->run->validated_by = $this->user->id;
        $this->run->validated_at = now();
        $this->run->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no calculation');

        app(GenerateOfficialPayslipsUseCase::class)->execute($this->run, $this->user->id);
    }
}
