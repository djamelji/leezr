<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-779 (UX4) — the person sees, before sending, the duration the EXISTING engine proposes for her
 * own leave. Always her own active record (never a parameter), tenant-bound, read-only, identical to
 * the manager's suggestion.
 */
class Ux4SelfLeavePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_leave']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_leave', 'name' => 'Leave', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = $this->company('Alpha');
        $this->other = $this->company('Beta');
    }

    private function company(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => strtolower($name), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_leave'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $c;
    }

    private function person(Company $company, bool $contract = true, string $status = Employee::STATUS_ACTIVE): array
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => false]);
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'user', 'company_role_id' => $role->id]);
        $emp = Employee::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'user_id' => $user->id, 'first_name' => 'Lina', 'last_name' => 'T',
            'status' => $status, 'hire_date' => '2026-01-01', 'employee_number' => 'E-'.uniqid(),
        ]);
        if ($contract) {
            EmploymentContract::withoutGlobalScopes()->create([
                'company_id' => $company->id, 'employee_id' => $emp->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire',
                'status' => 'active', 'is_current' => true, 'start_date' => '2026-01-01', 'weekly_hours' => 35,
            ]);
        }

        return [$user, $emp];
    }

    private function preview(User $user, Company $company, array $body)
    {
        return $this->actingAs($user)->postJson('/api/workforce/me/leaves/deduction-preview', $body, ['X-Company-Id' => $company->id]);
    }

    public function test_own_period_is_computed_by_the_existing_engine(): void
    {
        [$user] = $this->person($this->company);

        $this->preview($user, $this->company, ['date_from' => '2026-12-07', 'date_to' => '2026-12-08'])
            ->assertOk()
            ->assertJson(['days_count_hundredths' => 200, 'hours_count_hundredths' => 1400, 'daily_hours' => 7]);

        $this->preview($user, $this->company, ['date_from' => '2026-12-07', 'date_to' => '2026-12-07'])
            ->assertOk()->assertJson(['days_count_hundredths' => 100]);
    }

    public function test_employee_id_in_the_body_is_ignored(): void
    {
        [$user] = $this->person($this->company);
        [, $colleague] = $this->person($this->company, contract: false);

        // A colleague WITHOUT contract would answer 422 : the answer proves the caller's own record is used.
        $this->preview($user, $this->company, ['employee_id' => $colleague->id, 'date_from' => '2026-12-07', 'date_to' => '2026-12-07'])
            ->assertOk()->assertJson(['days_count_hundredths' => 100]);
    }

    public function test_invalid_period_and_missing_contract_are_refused(): void
    {
        [$user] = $this->person($this->company);
        $this->preview($user, $this->company, ['date_from' => '2026-12-08', 'date_to' => '2026-12-07'])->assertStatus(422);

        [$noContract] = $this->person($this->company, contract: false);
        $this->preview($noContract, $this->company, ['date_from' => '2026-12-07', 'date_to' => '2026-12-07'])->assertStatus(422);
    }

    public function test_no_active_record_in_the_current_company_means_no_preview(): void
    {
        // Member of Beta only : asking in Alpha's context gives nothing.
        [$betaUser] = $this->person($this->other);
        $this->preview($betaUser, $this->company, ['date_from' => '2026-12-07', 'date_to' => '2026-12-07'])->assertStatus(403);

        // Terminated record : no live self-service tool.
        [$gone] = $this->person($this->company, status: Employee::STATUS_TERMINATED);
        $this->preview($gone, $this->company, ['date_from' => '2026-12-07', 'date_to' => '2026-12-07'])->assertStatus(404);
    }

    public function test_preview_writes_nothing(): void
    {
        [$user, $emp] = $this->person($this->company);
        $this->preview($user, $this->company, ['date_from' => '2026-12-07', 'date_to' => '2026-12-11'])->assertOk();

        $this->assertSame(0, \App\Core\Workforce\LeaveRequest::withoutGlobalScopes()->where('employee_id', $emp->id)->count());
    }

    private function entry(Employee $e, string $date, string $status): \App\Core\Workforce\TimeEntry
    {
        return \App\Core\Workforce\TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $e->company_id, 'employee_id' => $e->id, 'date' => $date,
            'clock_in' => "{$date} 08:00:00", 'clock_out' => $status === 'completed' ? "{$date} 16:00:00" : null,
            'status' => $status, 'total_worked_minutes' => 0, 'total_break_minutes' => 0, 'source' => 'manual',
        ]);
    }

    public function test_an_entry_left_open_on_a_previous_day_is_shown_to_its_owner_only(): void
    {
        [$user, $emp] = $this->person($this->company);
        [$colleagueUser, $colleague] = $this->person($this->company);
        $old = $this->entry($emp, now()->subDays(3)->toDateString(), 'on_break');
        $this->entry($colleague, now()->subDays(2)->toDateString(), 'working');

        $mine = $this->actingAs($user)->getJson('/api/workforce/me', ['X-Company-Id' => $this->company->id])->assertOk();
        $this->assertSame($old->id, $mine->json('open_entry.id'));
        $this->assertSame('on_break', $mine->json('open_entry.status'));
        $this->assertNull($mine->json('today_clock'));

        // Clock-in stays refused by the unchanged engine rule : the screen now says why.
        $this->actingAs($user)->postJson('/api/workforce/me/clock-in', ['source' => 'manual'], ['X-Company-Id' => $this->company->id])->assertStatus(422);

        // A closed past day is not reported ; nor is a colleague's open entry.
        $old->update(['status' => 'completed', 'clock_out' => now()->subDays(3)->setTime(16, 0)]);
        $this->actingAs($user)->getJson('/api/workforce/me', ['X-Company-Id' => $this->company->id])->assertOk()->assertJsonPath('open_entry', null);
        $this->assertNotNull($this->actingAs($colleagueUser)->getJson('/api/workforce/me', ['X-Company-Id' => $this->company->id])->json('open_entry'));
    }
}
