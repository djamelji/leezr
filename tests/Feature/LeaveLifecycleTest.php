<?php

namespace Tests\Feature;

use App\Core\Events\ModuleEnabled;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\LeaveRequest;
use App\Core\Workforce\LeaveType;
use App\Core\Workforce\Services\LeaveLedger;
use App\Modules\Workforce\UseCases\AccrueLeaveUseCase;
use App\Modules\Workforce\UseCases\ActivateContractUseCase;
use App\Modules\Workforce\UseCases\ApproveLeaveRequestUseCase;
use App\Modules\Workforce\UseCases\RequestLeaveUseCase;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W-HR-UX-5 (P3) — cycle de vie des congés : seed des types à l'activation du module, acquisition
 * mensuelle (batch + à l'embauche) idempotente et bornée aux règles configurées, consommation au
 * moment prévu sans double consommation.
 */
class LeaveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_leave']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_leave', 'name' => 'Leave', 'is_enabled_globally' => true]);
        $this->company = Company::create(['name' => 'Leave Co', 'slug' => 'leave-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            CompanyModule::create(['company_id' => $this->company->id, 'module_key' => $m, 'is_enabled_for_company' => true]);
        }
    }

    private function activeEmployee(): Employee
    {
        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Tom', 'last_name' => 'B', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid()]);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $emp->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);

        return $emp;
    }

    private function annualLeaveType(): LeaveType
    {
        app(LeaveTypeSeeder::class)->seedForCompany($this->company->id, 'FR');

        return LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'annual_leave')->firstOrFail();
    }

    public function test_leave_types_seeded_on_module_enabled_and_idempotent(): void
    {
        $this->assertSame(0, LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        event(new ModuleEnabled($this->company, 'workforce_leave'));
        $after = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
        $this->assertGreaterThan(0, $after, 'les types FR sont seedés à l\'activation du module');

        // Ré-activation → aucun doublon (contrainte unique company_id+code).
        event(new ModuleEnabled($this->company, 'workforce_leave'));
        $this->assertSame($after, LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_other_module_does_not_seed_leave_types(): void
    {
        event(new ModuleEnabled($this->company, 'workforce_planning'));
        $this->assertSame(0, LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_monthly_accrual_credits_configured_share_and_is_idempotent(): void
    {
        $type = $this->annualLeaveType();   // annual 2500, accrual monthly
        $emp = $this->activeEmployee();
        $expected = (int) floor($type->annual_entitlement_hundredths / 12); // 208

        app(AccrueLeaveUseCase::class)->execute($this->company->id, '2026-06');
        $bal1 = LeaveLedger::computeBalance($this->company->id, $emp->id, $type->id);
        $this->assertSame($expected, (int) $bal1['accrued']);

        // Ré-exécution du MÊME mois → aucun double crédit.
        app(AccrueLeaveUseCase::class)->execute($this->company->id, '2026-06');
        $bal2 = LeaveLedger::computeBalance($this->company->id, $emp->id, $type->id);
        $this->assertSame($expected, (int) $bal2['accrued']);
    }

    public function test_accrual_skips_types_without_configured_rule(): void
    {
        $this->annualLeaveType();
        $emp = $this->activeEmployee();
        // 'unpaid' = accrual_mode none → aucun droit crédité automatiquement.
        $unpaid = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'unpaid')->firstOrFail();

        app(AccrueLeaveUseCase::class)->execute($this->company->id, '2026-06');

        $bal = LeaveLedger::computeBalance($this->company->id, $emp->id, $unpaid->id);
        $this->assertSame(0, (int) $bal['accrued'], 'pas de droit inventé pour un type sans règle d\'accrual');
    }

    public function test_accrual_triggered_on_first_contract_activation(): void
    {
        $this->annualLeaveType();
        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Hire', 'last_name' => 'Day', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => now()->toDateString(), 'employee_number' => 'E-'.uniqid()]);
        $contract = EmploymentContract::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $emp->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => now()->toDateString(), 'status' => 'draft', 'is_current' => false]);

        app(ActivateContractUseCase::class)->execute($contract);

        $type = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'annual_leave')->first();
        $bal = LeaveLedger::computeBalance($this->company->id, $emp->id, $type->id);
        $this->assertGreaterThan(0, (int) $bal['accrued'], 'le salarié dispose d\'un solde dès l\'activation du contrat');
    }

    public function test_hire_mid_month_accrual_is_prorated(): void
    {
        $this->annualLeaveType(); // CP annuel 2500 → part mensuelle floor(2500/12)=208
        // Embauche le 16 juillet (31 j) → couverture 16→31 = 16 jours → facteur 16/31.
        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Mid', 'last_name' => 'Month', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-07-16', 'employee_number' => 'E-'.uniqid()]);
        $type = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'annual_leave')->firstOrFail();

        app(AccrueLeaveUseCase::class)->executeForEmployee($this->company->id, $emp->id, '2026-07');

        $expected = (int) round(208 * 16 / 31); // 107
        $accrued = (int) LeaveLedger::computeBalance($this->company->id, $emp->id, $type->id)['accrued'];
        $this->assertSame($expected, $accrued, 'la part du mois d\'embauche est proratisée');
        $this->assertLessThan(208, $accrued, 'strictement inférieure à un mois complet');
    }

    public function test_hire_first_of_month_accrual_is_full(): void
    {
        $this->annualLeaveType();
        $emp = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'First', 'last_name' => 'Day', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2026-07-01', 'employee_number' => 'E-'.uniqid()]);
        $type = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'annual_leave')->firstOrFail();

        app(AccrueLeaveUseCase::class)->executeForEmployee($this->company->id, $emp->id, '2026-07');

        $this->assertSame(208, (int) LeaveLedger::computeBalance($this->company->id, $emp->id, $type->id)['accrued'], 'embauche le 1er = mois complet');
    }

    public function test_consume_due_consumes_approved_past_request_once(): void
    {
        $type = $this->annualLeaveType();
        $emp = $this->activeEmployee();
        // Donne un solde suffisant.
        app(AccrueLeaveUseCase::class)->execute($this->company->id, now()->format('Y-m'));

        $req = app(RequestLeaveUseCase::class)->execute(
            employee: $emp, leaveTypeId: $type->id,
            dateFrom: now()->subDay()->toDateString(), dateTo: now()->subDay()->toDateString(),
            daysCountHundredths: 100, reason: null,
        );
        app(ApproveLeaveRequestUseCase::class)->execute($req->fresh(), null, 'ok');
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $req->fresh()->status);

        // date_from = hier → éligible à la consommation.
        $this->artisan('leave:consume-due')->assertSuccessful();
        $this->assertSame(LeaveRequest::STATUS_CONSUMED, $req->fresh()->status);
        $consumedAfter = (int) LeaveLedger::computeBalance($this->company->id, $emp->id, $type->id)['consumed'];
        $this->assertSame(100, $consumedAfter);

        // Ré-exécution → pas de double consommation.
        $this->artisan('leave:consume-due')->assertSuccessful();
        $this->assertSame(100, (int) LeaveLedger::computeBalance($this->company->id, $emp->id, $type->id)['consumed']);
    }

    public function test_future_approved_request_is_not_consumed_yet(): void
    {
        $type = $this->annualLeaveType();
        $emp = $this->activeEmployee();
        app(AccrueLeaveUseCase::class)->execute($this->company->id, now()->format('Y-m'));

        $req = app(RequestLeaveUseCase::class)->execute(
            employee: $emp, leaveTypeId: $type->id,
            dateFrom: now()->addDays(10)->toDateString(), dateTo: now()->addDays(10)->toDateString(),
            daysCountHundredths: 100, reason: null,
        );
        app(ApproveLeaveRequestUseCase::class)->execute($req->fresh(), null, 'ok');

        $this->artisan('leave:consume-due')->assertSuccessful();
        $this->assertSame(LeaveRequest::STATUS_APPROVED, $req->fresh()->status, 'un congé futur n\'est pas encore consommé');
    }
}
