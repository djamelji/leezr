<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
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
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E3-B / B2 (ADR-698) — Pointages, écarts & anomalies :
 *  1) le premier jour de semaine du PLANNING (endpoint `week`) respecte la préférence Company
 *     (week_start_day 0..6) et NON le lundi codé en dur — réserve B1 #1 corrigée « au bon niveau » ;
 *  2) le COCKPIT (summary + board prévu/réel réconcilié) fonctionne à l'identique quel que soit le
 *     secteur d'activité (jobdomain) — aucune spécificité métier dans le composant générique,
 *     aucune dépendance Logistics. Réutilise les services/read models existants.
 *
 * Idiome copié de WorkforceTimeCockpitTest (helpers emp/shift/entry/user) + WorkforcePlanningOperationsTest.
 */
class WorkforceCockpitB2Test extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['workforce', 'workforce_planning', 'workforce_leave'];

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();

        Market::create(['key' => 'FR', 'name' => 'FR', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (self::MODULES as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
    }

    /** Crée une Company d'un secteur donné (un seul jobdomain) + modules workforce actifs. */
    private function company(string $jobdomainKey): Company
    {
        Jobdomain::firstOrCreate(['key' => $jobdomainKey], ['label' => ucfirst($jobdomainKey), 'default_modules' => self::MODULES]);
        $c = Company::create(['name' => ucfirst($jobdomainKey).' Co', 'slug' => $jobdomainKey.'-'.uniqid(), 'jobdomain_key' => $jobdomainKey, 'market_key' => 'FR']);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }

        return $c;
    }

    private function emp(Company $c, string $f, ?int $mgr = null): Employee
    {
        return Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => $f, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'employee_number' => 'E-'.uniqid()]);
    }

    private function shift(Company $c, Employee $e, string $date, string $in, string $out, ?string $endDate = null): Shift
    {
        return Shift::withoutGlobalScopes()->create(['company_id' => $c->id, 'employee_id' => $e->id, 'date' => $date, 'start_at' => "{$date} {$in}:00", 'end_at' => ($endDate ?? $date)." {$out}:00", 'timezone' => 'Europe/Paris', 'status' => 'published', 'template_snapshot' => ['break_duration_minutes' => 60]]);
    }

    private function entry(Company $c, Employee $e, string $date, ?string $in, ?string $out, string $status = 'completed', int $worked = 420, int $break = 60): TimeEntry
    {
        return TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $c->id, 'employee_id' => $e->id, 'date' => $date,
            'clock_in' => $in ? "{$date} {$in}:00" : null, 'clock_out' => $out ? "{$date} {$out}:00" : null,
            'status' => $status, 'total_worked_minutes' => $worked, 'total_break_minutes' => $break, 'source' => 'manual',
        ]);
    }

    private function user(Company $c, array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    // ── 1) Premier jour de semaine du planning = préférence Company (pas lundi en dur) ──

    public function test_planning_week_respects_company_sunday_start(): void
    {
        $c = $this->company('logistique');
        $e = $this->emp($c, 'Omar');
        $u = $this->user($c, ['workforce.planning_view']);

        // Mercredi de référence ; le dimanche de SA semaine (début dimanche) est le 2026-11-01.
        $wednesday = '2026-11-04';
        $sunday = Carbon::parse($wednesday)->startOfWeek(Carbon::SUNDAY)->toDateString(); // 2026-11-01
        $this->assertSame('2026-11-01', $sunday);
        $sundayShift = $this->shift($c, $e, $sunday, '08:00', '12:00');

        // Préférence dimanche → la semaine du mercredi inclut le dimanche 01/11.
        $c->update(['week_start_day' => 0]);
        $ids = collect($this->actingAs($u)->getJson("/api/workforce/planning/shifts/week?date={$wednesday}", ['X-Company-Id' => $c->id])->assertOk()->json())->pluck('id');
        $this->assertContains($sundayShift->id, $ids->all(), 'Semaine dimanche-first : le shift du dimanche doit être inclus.');

        // Contrôle : préférence lundi (défaut FR) → ce dimanche appartient à la semaine PRÉCÉDENTE, exclu.
        $c->update(['week_start_day' => 1]);
        $idsMonday = collect($this->actingAs($u)->getJson("/api/workforce/planning/shifts/week?date={$wednesday}", ['X-Company-Id' => $c->id])->assertOk()->json())->pluck('id');
        $this->assertNotContains($sundayShift->id, $idsMonday->all(), 'Semaine lundi-first : le shift du dimanche précédent doit être exclu.');
    }

    // ── 2) Cockpit identique quel que soit le secteur (jobdomain) ──

    /** @dataProvider sectorProvider */
    public function test_cockpit_board_is_sector_independent(string $jobdomain, string $in, string $out, ?string $endDate): void
    {
        $day = '2026-06-02';
        $c = $this->company($jobdomain);
        $e = $this->emp($c, 'Pat');
        // Prévu (shift) + réel (pointage) → réconciliation « matched », quel que soit le secteur.
        $this->shift($c, $e, $day, $in, $out, $endDate);
        $this->entry($c, $e, $day, $in, $out);
        $u = $this->user($c, ['workforce.timesheet_view', 'workforce.time_manage']);

        $summary = $this->actingAs($u)->getJson("/api/workforce/time-entries/cockpit/summary?date={$day}", ['X-Company-Id' => $c->id])->assertOk();
        $summary->assertJsonFragment(['completed' => 1]);

        $rows = collect($this->actingAs($u)->getJson("/api/workforce/time-entries/cockpit/board?date={$day}", ['X-Company-Id' => $c->id])->assertOk()->json());
        $row = $rows->firstWhere('employee_id', $e->id);
        $this->assertNotNull($row, "Le board doit lister le collaborateur pour le secteur {$jobdomain}.");
        $this->assertSame('matched', $row['reconciliation']);
        $this->assertNotNull($row['planned_start']);
        $this->assertNotNull($row['actual_start']);
    }

    public static function sectorProvider(): array
    {
        return [
            // secteur, prise de poste, fin, (date de fin si minuit traversé)
            'restaurant (service midi)' => ['restauration', '11:00', '15:00', null],
            'nettoyage (site client matinal)' => ['nettoyage', '06:00', '10:00', null],
            'salon (horaire individuel)' => ['salon', '10:30', '18:30', null],
            'logistique (équipe de nuit)' => ['logistique', '22:00', '06:00', '2026-06-03'],
        ];
    }
}
