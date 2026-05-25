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
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimeEntryBreak;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\DeleteTimeEntryUseCase;
use App\Modules\Workforce\UseCases\UpdateTimeEntryUseCase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * W2 — Time Tracking Réel Tests
 *
 * Tests: UpdateTimeEntry, DeleteTimeEntry, API endpoints (company index,
 * update, delete, anomalies), multi-tenant isolation, anomaly detection.
 */
class WorkforceTimeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Employee $employee;
    private TimeEntry $completedEntry;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce']]);

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

        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);

        $this->company = Company::create([
            'name' => 'Time Test Co',
            'slug' => 'time-test-co',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);

        CompanyModuleActivationReason::create([
            'company_id' => $this->company->id,
            'module_key' => 'workforce',
            'reason' => CompanyModuleActivationReason::REASON_DIRECT,
        ]);

        $this->user = User::factory()->create();
        Membership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'owner',
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'email' => 'jean@time-test.com',
            'employee_number' => 'EMP-T001',
            'hire_date' => '2024-01-01',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->completedEntry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-19',
            'clock_in' => Carbon::parse('2026-05-19 08:00:00'),
            'clock_out' => Carbon::parse('2026-05-19 17:00:00'),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
            'total_worked_minutes' => 540,
            'total_break_minutes' => 0,
        ]);

        Model::reguard();
    }

    // ── UpdateTimeEntryUseCase ────────────────────────────────

    public function test_update_corrects_clock_times(): void
    {
        $useCase = app(UpdateTimeEntryUseCase::class);

        $updated = $useCase->execute($this->completedEntry, [
            'clock_in' => '2026-05-19 09:00:00',
            'clock_out' => '2026-05-19 18:00:00',
        ]);

        $this->assertEquals('09:00', $updated->clock_in->format('H:i'));
        $this->assertEquals('18:00', $updated->clock_out->format('H:i'));
        $this->assertEquals(540, $updated->total_worked_minutes);
    }

    public function test_update_corrects_date(): void
    {
        $useCase = app(UpdateTimeEntryUseCase::class);

        $updated = $useCase->execute($this->completedEntry, [
            'date' => '2026-05-18',
        ]);

        $this->assertEquals('2026-05-18', $updated->date->toDateString());
    }

    public function test_update_rejects_working_status(): void
    {
        Model::unguard();
        $entry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-20',
            'clock_in' => now(),
            'status' => TimeEntry::STATUS_WORKING,
            'source' => 'manual',
        ]);
        Model::reguard();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot update a time entry with status');

        app(UpdateTimeEntryUseCase::class)->execute($entry, [
            'clock_out' => now()->addHours(8)->toIso8601String(),
        ]);
    }

    public function test_update_rejects_clock_in_after_clock_out(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Clock in must be before clock out');

        app(UpdateTimeEntryUseCase::class)->execute($this->completedEntry, [
            'clock_in' => '2026-05-19 20:00:00',
            'clock_out' => '2026-05-19 08:00:00',
        ]);
    }

    public function test_update_blocked_by_locked_timesheet(): void
    {
        Model::unguard();
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-19',
            'period_end' => '2026-05-25',
            'status' => 'locked',
        ]);

        TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'timesheet_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-19',
            'worked_minutes' => 540,
            'break_minutes' => 0,
            'source_snapshot' => [],
        ]);
        Model::reguard();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('locked or approved timesheet');

        app(UpdateTimeEntryUseCase::class)->execute($this->completedEntry, [
            'clock_in' => '2026-05-19 09:00:00',
        ]);
    }

    // ── DeleteTimeEntryUseCase ────────────────────────────────

    public function test_delete_removes_entry_and_breaks(): void
    {
        Model::unguard();
        TimeEntryBreak::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'time_entry_id' => $this->completedEntry->id,
            'start_at' => Carbon::parse('2026-05-19 12:00:00'),
            'end_at' => Carbon::parse('2026-05-19 13:00:00'),
            'type' => 'lunch',
            'duration_minutes' => 60,
        ]);
        Model::reguard();

        $entryId = $this->completedEntry->id;

        app(DeleteTimeEntryUseCase::class)->execute($this->completedEntry);

        $this->assertDatabaseMissing('workforce_time_entries', ['id' => $entryId]);
        $this->assertDatabaseMissing('workforce_time_entry_breaks', ['time_entry_id' => $entryId]);
    }

    public function test_delete_rejects_working_entry(): void
    {
        Model::unguard();
        $entry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-20',
            'clock_in' => now(),
            'status' => TimeEntry::STATUS_WORKING,
            'source' => 'manual',
        ]);
        Model::reguard();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Clock out first');

        app(DeleteTimeEntryUseCase::class)->execute($entry);
    }

    public function test_delete_blocked_by_approved_timesheet(): void
    {
        Model::unguard();
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'period_start' => '2026-05-19',
            'period_end' => '2026-05-25',
            'status' => 'approved',
        ]);

        TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'timesheet_period_id' => $period->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-19',
            'worked_minutes' => 540,
            'break_minutes' => 0,
            'source_snapshot' => [],
        ]);
        Model::reguard();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('locked or approved timesheet');

        app(DeleteTimeEntryUseCase::class)->execute($this->completedEntry);
    }

    public function test_delete_allowed_for_idle_entry(): void
    {
        Model::unguard();
        $entry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-20',
            'clock_in' => Carbon::parse('2026-05-20 08:00:00'),
            'status' => TimeEntry::STATUS_IDLE,
            'source' => 'manual',
        ]);
        Model::reguard();

        $entryId = $entry->id;

        app(DeleteTimeEntryUseCase::class)->execute($entry);

        $this->assertDatabaseMissing('workforce_time_entries', ['id' => $entryId]);
    }

    // ── API Endpoints ────────────────────────────────────────

    public function test_company_index_returns_paginated_entries(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/workforce/time-entries/company');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'total']);
    }

    public function test_company_index_filters_by_date(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/workforce/time-entries/company?from=2026-05-19&to=2026-05-19');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_company_index_filters_by_employee(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/workforce/time-entries/company?employee_id=' . $this->employee->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_update_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson("/api/workforce/time-entries/{$this->completedEntry->id}", [
                'clock_in' => '2026-05-19 09:30:00',
                'clock_out' => '2026-05-19 17:30:00',
            ]);

        $response->assertOk();
        $this->completedEntry->refresh();
        $this->assertEquals('09:30', $this->completedEntry->clock_in->format('H:i'));
    }

    public function test_delete_endpoint(): void
    {
        $entryId = $this->completedEntry->id;

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->deleteJson("/api/workforce/time-entries/{$entryId}");

        $response->assertOk();
        $this->assertDatabaseMissing('workforce_time_entries', ['id' => $entryId]);
    }

    public function test_anomalies_endpoint(): void
    {
        Model::unguard();
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-19',
            'clock_in' => Carbon::parse('2026-05-19 08:00:00'),
            'status' => TimeEntry::STATUS_WORKING,
            'source' => 'manual',
        ]);
        Model::reguard();

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/workforce/time-entries/anomalies?from=2026-05-19&to=2026-05-19');

        $response->assertOk();
        $types = array_column($response->json(), 'type');
        $this->assertContains('missing_clock_out', $types);
    }

    // ── Multi-tenant isolation ────────────────────────────────

    public function test_cannot_update_other_company_entry(): void
    {
        Model::unguard();
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co-time',
            'jobdomain_key' => 'logistique',
            'market_key' => 'FR',
        ]);

        $otherEntry = TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-19',
            'clock_in' => Carbon::parse('2026-05-19 08:00:00'),
            'clock_out' => Carbon::parse('2026-05-19 17:00:00'),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
        ]);
        Model::reguard();

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson("/api/workforce/time-entries/{$otherEntry->id}", [
                'clock_in' => '2026-05-19 10:00:00',
            ]);

        $response->assertNotFound();
    }

    // ── Anomaly detection ────────────────────────────────────

    public function test_detects_excessive_hours_anomaly(): void
    {
        Model::unguard();
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-18',
            'clock_in' => Carbon::parse('2026-05-18 06:00:00'),
            'clock_out' => Carbon::parse('2026-05-18 19:00:00'),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
            'total_worked_minutes' => 780,
            'total_break_minutes' => 0,
        ]);
        Model::reguard();

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/workforce/time-entries/anomalies?from=2026-05-18&to=2026-05-18');

        $response->assertOk();
        $types = array_column($response->json(), 'type');
        $this->assertContains('excessive_hours', $types);
    }

    public function test_detects_missing_break_anomaly(): void
    {
        Model::unguard();
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-18',
            'clock_in' => Carbon::parse('2026-05-18 08:00:00'),
            'clock_out' => Carbon::parse('2026-05-18 16:00:00'),
            'status' => TimeEntry::STATUS_COMPLETED,
            'source' => 'manual',
            'total_worked_minutes' => 480,
            'total_break_minutes' => 10,
        ]);
        Model::reguard();

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/workforce/time-entries/anomalies?from=2026-05-18&to=2026-05-18');

        $response->assertOk();
        $types = array_column($response->json(), 'type');
        $this->assertContains('missing_break', $types);
    }
}
