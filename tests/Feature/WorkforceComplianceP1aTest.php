<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\Services\WorkTimeComplianceChecker;
use App\Modules\Workforce\UseCases\GenerateTimesheetUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P1a (ADR-703) — contrôle réglementaire sur le CONSTATÉ (lecture seule). Vérifie : statuts explicites
 * (pass/warning/violation/not_verifiable/not_applicable) ; aucune mutation de la feuille (anomaly_count P0
 * inchangé) ; semaine réglementaire ≠ semaine d'affichage ; non vérifiable ≠ conforme ; forfait non
 * naïvement soumis aux maxima ; scope/tenant/permission. Horloge figée.
 */
class WorkforceComplianceP1aTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        Model::unguard();
        \App\Core\Jobdomains\Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        \App\Core\Markets\Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'CP', 'slug' => 'cp-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        // Règles de temps de travail FR (daily_hours_max 10h, weekly_hours_max 48h, repos 11h/35h) pour le résolveur.
        $this->seed(\Database\Seeders\MarketRuleSetSeeder::class);
        $this->employee = Employee::create(['company_id' => $this->company->id, 'first_name' => 'C', 'last_name' => 'P', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(string $date, string $in, string $out, int $worked, ?Employee $e = null, ?string $outDate = null): void
    {
        $e ??= $this->employee;
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => $date,
            'clock_in' => "{$date} {$in}:00", 'clock_out' => ($outDate ?? $date)." {$out}:00",
            'status' => TimeEntry::STATUS_COMPLETED, 'total_worked_minutes' => $worked, 'total_break_minutes' => 60, 'source' => 'manual',
        ]);
    }

    private function generate(string $start, string $end, ?Employee $e = null): TimesheetPeriod
    {
        return app(GenerateTimesheetUseCase::class)->execute($this->company->id, ($e ?? $this->employee)->id, $start, $end);
    }

    private function statusFor(array $results, string $ruleKey): array
    {
        return collect($results)->where('rule_key', $ruleKey)->pluck('status')->all();
    }

    public function test_compliant_week_passes(): void
    {
        // Lun→Ven 8h, repos nocturne ~15h, semaine ~40h < 48h, période = semaine civile complète.
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $d) {
            $this->entry($d, '09:00', '17:00', 480);
        }
        $period = $this->generate('2026-11-02', '2026-11-08');
        $r = app(WorkTimeComplianceChecker::class)->check($period);

        $this->assertContains('pass', $this->statusFor($r, 'daily_hours_max'));
        $this->assertNotContains('violation', collect($r)->pluck('status')->all());
        $this->assertContains('pass', $this->statusFor($r, 'weekly_hours_max'));
    }

    public function test_daily_max_violation(): void
    {
        $this->entry('2026-11-02', '06:00', '19:00', 720); // 12h > 10h (FR daily_hours_max)
        $period = $this->generate('2026-11-02', '2026-11-08');
        $r = app(WorkTimeComplianceChecker::class)->check($period);

        $this->assertContains('violation', $this->statusFor($r, 'daily_hours_max'));
    }

    public function test_daily_rest_violation(): void
    {
        $this->entry('2026-11-02', '08:00', '22:00', 780);
        $this->entry('2026-11-03', '06:00', '14:00', 420); // repos 22:00→06:00 = 8h < 11h
        $period = $this->generate('2026-11-02', '2026-11-08');
        $r = app(WorkTimeComplianceChecker::class)->check($period);

        $this->assertContains('violation', $this->statusFor($r, 'daily_rest_min_hours'));
    }

    public function test_weekly_max_violation_on_full_regulatory_week(): void
    {
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07'] as $d) {
            $this->entry($d, '08:00', '17:00', 540); // 6 × 9h = 54h > 48h
        }
        $period = $this->generate('2026-11-02', '2026-11-08');
        $r = app(WorkTimeComplianceChecker::class)->check($period);

        $this->assertContains('violation', $this->statusFor($r, 'weekly_hours_max'));
    }

    public function test_weekly_not_verifiable_when_period_misaligned(): void
    {
        // Période dimanche→samedi : ne couvre PAS intégralement une semaine civile lundi→dimanche.
        $this->entry('2026-11-02', '09:00', '17:00', 480);
        $period = $this->generate('2026-11-01', '2026-11-07'); // dim→sam
        $r = app(WorkTimeComplianceChecker::class)->check($period);

        $this->assertContains('not_verifiable', $this->statusFor($r, 'weekly_hours_max'));
        $this->assertNotContains('violation', $this->statusFor($r, 'weekly_hours_max'));
    }

    public function test_forfait_hour_maxima_not_verifiable(): void
    {
        EmploymentContract::withoutGlobalScopes()->where('employee_id', $this->employee->id)->update(['work_model_key' => 'forfait_jours']);
        $this->entry('2026-11-02', '06:00', '20:00', 780);
        $period = $this->generate('2026-11-02', '2026-11-08');
        $r = app(WorkTimeComplianceChecker::class)->check($period);

        // Régime forfait : maximum horaire NON appliqué naïvement → non vérifiable (ni violation, ni conforme).
        $this->assertContains('not_verifiable', $this->statusFor($r, 'daily_hours_max'));
        $this->assertNotContains('violation', $this->statusFor($r, 'daily_hours_max'));
    }

    public function test_check_does_not_mutate_the_timesheet(): void
    {
        $this->entry('2026-11-02', '06:00', '19:00', 720);
        $period = $this->generate('2026-11-02', '2026-11-08');
        $beforeCount = $period->fresh()->anomaly_count;
        $beforeLine = $period->lines()->first()->fresh()->updated_at;

        app(WorkTimeComplianceChecker::class)->check($period);

        $this->assertSame($beforeCount, $period->fresh()->anomaly_count, 'La consultation ne modifie pas anomaly_count (P0 préservé).');
        $this->assertEquals($beforeLine, $period->lines()->first()->fresh()->updated_at, 'La consultation ne mute aucune ligne.');
    }

    // ── Endpoint : scope / tenant / permission ──

    private function user(array $perms, ?Employee $as = null): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);
        if ($as) {
            $as->update(['user_id' => $u->id]);
        }

        return $u;
    }

    public function test_compliance_endpoint_scope_and_permission(): void
    {
        $this->entry('2026-11-02', '06:00', '19:00', 720);
        $period = $this->generate('2026-11-02', '2026-11-08');
        $h = ['X-Company-Id' => $this->company->id];

        // view-all (time_manage) → OK, statut serveur présent.
        $viewer = $this->user(['workforce.timesheet_view', 'workforce.time_manage']);
        $this->actingAs($viewer)->getJson("/api/workforce/timesheets/{$period->id}/compliance", $h)
            ->assertOk()->assertJsonStructure(['results', 'summary' => ['violation', 'warning', 'not_verifiable'], 'computed_at']);

        // Sans permission de lecture → 403 (middleware).
        $noView = $this->user(['workforce.planning_view']);
        $this->actingAs($noView)->getJson("/api/workforce/timesheets/{$period->id}/compliance", $h)->assertForbidden();
    }
}
