<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\Shift;
use App\Core\Workforce\WorkLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforcePlanningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private WorkLocation $location;
    private ScheduleTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create([
            'key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR',
            'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33',
            'flag_code' => 'fr', 'flag_svg' => '',
        ]);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_planning', 'name' => 'Planning', 'is_enabled_globally' => true]);

        $this->company = Company::create([
            'name' => 'W4 Test Co', 'slug' => 'w4-test-co',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR',
        ]);
        CompanyModuleActivationReason::create([
            'company_id' => $this->company->id, 'module_key' => 'workforce',
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
        ]);
        CompanyModuleActivationReason::create([
            'company_id' => $this->company->id, 'module_key' => 'workforce_planning',
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
        ]);

        $this->user = User::factory()->create();
        Membership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'owner']);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'first_name' => 'Paul', 'last_name' => 'Dupuis',
            'email' => 'paul@w4test.com', 'employee_number' => 'EMP-W4',
            'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE,
        ]);

        EmploymentContract::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'contract_type' => 'cdi', 'work_model_key' => 'on_site',
            'start_date' => '2024-01-01', 'weekly_hours' => 35,
            'status' => 'active', 'is_current' => true,
        ]);

        $this->location = WorkLocation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Bureau Principal',
            'type' => 'internal', 'enabled' => true, 'sort_order' => 1,
        ]);

        $this->template = ScheduleTemplate::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Journée standard',
            'start_time' => '09:00', 'end_time' => '17:00',
            'break_duration_minutes' => 60, 'enabled' => true, 'sort_order' => 1,
        ]);

        Model::reguard();
    }

    private function apiGet(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->getJson($url, ['X-Company-Id' => $this->company->id]);
    }

    private function apiPost(string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson($url, $data, ['X-Company-Id' => $this->company->id]);
    }

    private function apiPut(string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->putJson($url, $data, ['X-Company-Id' => $this->company->id]);
    }

    private function apiDel(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->deleteJson($url, [], ['X-Company-Id' => $this->company->id]);
    }

    // ══════════════════════════════════════════════════════════
    // WORK LOCATIONS
    // ══════════════════════════════════════════════════════════

    public function test_list_locations(): void
    {
        $this->apiGet('/api/workforce/planning/locations')->assertOk();
    }

    public function test_create_location(): void
    {
        $response = $this->apiPost('/api/workforce/planning/locations', [
            'name' => 'Entrepôt Nord', 'type' => 'internal', 'address' => '10 rue des Acacias',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('Entrepôt Nord', $response->json('name'));
    }

    public function test_update_location(): void
    {
        $response = $this->apiPut("/api/workforce/planning/locations/{$this->location->id}", [
            'name' => 'Bureau Rénové',
        ]);
        $response->assertOk();
        $this->assertEquals('Bureau Rénové', $response->json('name'));
    }

    public function test_delete_location(): void
    {
        $this->apiDel("/api/workforce/planning/locations/{$this->location->id}")->assertOk();
        $this->assertNull(WorkLocation::withoutGlobalScopes()->find($this->location->id));
    }

    // ══════════════════════════════════════════════════════════
    // SCHEDULE TEMPLATES
    // ══════════════════════════════════════════════════════════

    public function test_list_templates(): void
    {
        $this->apiGet('/api/workforce/planning/templates')->assertOk();
    }

    public function test_create_template(): void
    {
        $response = $this->apiPost('/api/workforce/planning/templates', [
            'name' => 'Nuit', 'start_time' => '22:00', 'end_time' => '06:00',
            'break_duration_minutes' => 30,
        ]);
        $response->assertStatus(201);
        $this->assertEquals('Nuit', $response->json('name'));
    }

    public function test_update_template(): void
    {
        $response = $this->apiPut("/api/workforce/planning/templates/{$this->template->id}", [
            'break_duration_minutes' => 45,
        ]);
        $response->assertOk();
        $this->assertEquals(45, $response->json('break_duration_minutes'));
    }

    public function test_delete_template(): void
    {
        $this->apiDel("/api/workforce/planning/templates/{$this->template->id}")->assertOk();
        $this->assertNull(ScheduleTemplate::withoutGlobalScopes()->find($this->template->id));
    }

    // ══════════════════════════════════════════════════════════
    // SHIFTS
    // ══════════════════════════════════════════════════════════

    public function test_create_shift(): void
    {
        $response = $this->apiPost('/api/workforce/planning/shifts', [
            'employee_id' => $this->employee->id,
            'date' => '2026-06-01',
            'start_at' => '2026-06-01 09:00:00',
            'end_at' => '2026-06-01 17:00:00',
            'timezone' => 'Europe/Paris',
        ]);
        $response->assertStatus(201);
        $this->assertEquals('draft', $response->json('status'));
    }

    public function test_update_shift(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->apiPut("/api/workforce/planning/shifts/{$shift->id}", [
            'notes' => 'Updated notes',
        ]);
        $response->assertOk();
    }

    public function test_delete_draft_shift(): void
    {
        $shift = $this->createDraftShift();
        $this->apiDel("/api/workforce/planning/shifts/{$shift->id}")->assertOk();
    }

    public function test_cannot_delete_published_shift(): void
    {
        $shift = $this->createDraftShift();
        $shift->update(['status' => 'published', 'published_at' => now()]);
        $this->apiDel("/api/workforce/planning/shifts/{$shift->id}")->assertStatus(422);
    }

    public function test_publish_shifts(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->apiPost('/api/workforce/planning/shifts/publish', [
            'shift_ids' => [$shift->id],
        ]);
        $response->assertOk();
    }

    public function test_cancel_shift(): void
    {
        $shift = $this->createDraftShift();

        $response = $this->apiPost("/api/workforce/planning/shifts/{$shift->id}/cancel", [
            'reason' => 'Employee sick',
        ]);
        $response->assertOk();
        $this->assertEquals('cancelled', $response->json('status'));
    }

    public function test_week_view(): void
    {
        $this->createDraftShift('2026-06-01');

        $response = $this->apiGet('/api/workforce/planning/shifts/week?date=2026-06-01');
        $response->assertOk();
    }

    public function test_statistics(): void
    {
        $this->apiGet('/api/workforce/planning/shifts/statistics')->assertOk();
    }

    // ══════════════════════════════════════════════════════════
    // CROSS-COMPANY ISOLATION
    // ══════════════════════════════════════════════════════════

    public function test_cannot_access_other_company_shift(): void
    {
        Model::unguard();
        $other = Company::create([
            'name' => 'Other Co', 'slug' => 'other-co-w4',
            'jobdomain_key' => 'logistique', 'market_key' => 'FR',
        ]);
        $otherShift = Shift::withoutGlobalScopes()->create([
            'company_id' => $other->id, 'employee_id' => $this->employee->id,
            'date' => '2026-06-01', 'start_at' => '2026-06-01 09:00:00',
            'end_at' => '2026-06-01 17:00:00', 'timezone' => 'Europe/Paris',
            'status' => 'draft', 'template_snapshot' => json_encode([]),
        ]);
        Model::reguard();

        $this->apiDel("/api/workforce/planning/shifts/{$otherShift->id}")->assertStatus(404);
    }

    // ══════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════

    private function createDraftShift(string $date = '2026-06-01'): Shift
    {
        Model::unguard();
        $shift = Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => $date,
            'start_at' => "{$date} 09:00:00",
            'end_at' => "{$date} 17:00:00",
            'timezone' => 'Europe/Paris',
            'status' => 'draft',
            'template_snapshot' => json_encode([]),
        ]);
        Model::reguard();

        return $shift;
    }
}
