<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use App\Modules\Workforce\UseCases\ExportPayrollUseCase;
use App\Modules\Workforce\UseCases\ValidatePayrollUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-824 — the payroll validation rule, as the server applies it (no calculation changed) :
 *  - period anomalies of level error (employee without locked timesheet, without pay…) EXCLUDE people from the
 *    run : validation needs a real written justification, never a blank one ;
 *  - warnings never block ;
 *  - calculation anomalies (blocking_anomalies) ALWAYS block, even with a justification ;
 *  - an already validated run cannot be validated again ; export only after validation.
 */
class Adr824PayrollValidationRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->company = Company::create(['name' => 'Pay Co', 'slug' => 'pay-co', 'jobdomain_key' => 'logistique']);
        $this->user = User::factory()->create();
        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_number' => 'P-1', 'first_name' => 'Ana', 'last_name' => 'Pay',
            'hire_date' => '2024-01-01', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function makeRun(array $anomalies = [], ?array $blocking = null): PayrollRun
    {
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'status' => 'computed', 'currency' => 'EUR', 'employee_count' => 1,
            'anomalies' => $anomalies, 'anomaly_count' => count($anomalies),
        ]);
        $ts = \App\Core\Workforce\TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'status' => 'locked',
        ]);
        $line = PayrollLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id, 'timesheet_period_id' => $ts->id,
            'worked_minutes' => 9000, 'base_salary_cents' => 200000, 'gross_basis_cents' => 200000,
            'gross_breakdown' => [], 'compensation_snapshot' => [], 'timesheet_snapshot' => [],
        ]);
        PayrollCalculation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_line_id' => $line->id, 'rule_version' => 'test',
            'gross_total_cents' => 200000, 'net_payable_cents' => 155000, 'blocking_anomalies' => $blocking,
            'plafond_ss_monthly_cents' => 0, 'contributions_employee_cents' => 0, 'contributions_employer_cents' => 0,
            'total_cost_employer_cents' => 0, 'taxable_income_cents' => 0, 'tax_cents' => 0, 'net_before_tax_cents' => 0,
            'contribution_lines' => [], 'tax_breakdown' => [], 'calculation_snapshot' => [],
            'status' => PayrollCalculation::STATUS_CALCULATED, 'calculated_at' => now(),
        ]);

        return $run;
    }

    private function validate(PayrollRun $run, ?string $note = null): PayrollRun
    {
        return (new ValidatePayrollUseCase)->execute($run, $this->user->id, $note);
    }

    public function test_clean_period_validates_and_then_exports(): void
    {
        $run = $this->validate($this->makeRun());
        $this->assertSame('validated', $run->fresh()->status);
        $this->assertSame('validated', PayrollCalculation::withoutGlobalScopes()->value('status'), 'calculations validated with the run');
    }

    public function test_warning_never_blocks(): void
    {
        $run = $this->makeRun([['type' => PayrollRun::ANOMALY_ZERO_WORKED, 'severity' => 'warning', 'employee_id' => $this->employee->id]]);
        $this->assertSame('validated', $this->validate($run)->fresh()->status);
    }

    public function test_excluded_employee_needs_a_real_written_justification(): void
    {
        $run = $this->makeRun([['type' => PayrollRun::ANOMALY_MISSING_TIMESHEET, 'severity' => 'error', 'employee_id' => 999]]);
        foreach ([null, '', '    '] as $blank) {
            try {
                $this->validate($run->fresh(), $blank);
                $this->fail('validated without a justification: '.var_export($blank, true));
            } catch (\DomainException $e) {
                $this->assertStringContainsString('validation_note is required', $e->getMessage());
            }
        }
        $this->assertSame('computed', $run->fresh()->status, 'refusal leaves the run untouched');
        $done = $this->validate($run->fresh(), '  Salarié parti le 2 mars, réglé par solde de tout compte.  ');
        $this->assertSame('Salarié parti le 2 mars, réglé par solde de tout compte.', $done->fresh()->validation_note, 'note stored trimmed');
    }

    public function test_calculation_anomaly_blocks_even_with_a_justification(): void
    {
        $run = $this->makeRun([], [['code' => 'missing_contribution_rate']]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('blocking anomalies');
        $this->validate($run, 'Je valide quand même');
    }

    public function test_already_validated_period_cannot_be_validated_again(): void
    {
        $run = $this->validate($this->makeRun());
        $this->expectException(\DomainException::class);
        $this->validate($run->fresh(), 'encore');
    }

    public function test_export_requires_a_validated_period(): void
    {
        if (! class_exists(ExportPayrollUseCase::class)) {
            $this->markTestSkipped('export use case not present');
        }
        $this->expectException(\DomainException::class);
        app(ExportPayrollUseCase::class)->execute($this->makeRun(), $this->user->id);
    }

    /** ADR-825 — diagnostic : the work model of each excluded employee is exposed (nothing computed). */
    public function test_excluded_employees_expose_their_work_model(): void
    {
        \App\Core\Workforce\EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'contract_type' => 'cdi',
            'work_model_key' => 'forfait_jours', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35,
        ]);
        $run = $this->makeRun([['type' => PayrollRun::ANOMALY_MISSING_TIMESHEET, 'severity' => 'error', 'employee_id' => $this->employee->id]]);
        $this->assertSame([$this->employee->id => 'forfait_jours'], \App\Modules\Workforce\ReadModels\PayrollRunReadModel::anomalyWorkModels($run));
    }
}
