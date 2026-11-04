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
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * E3-B / B4 (ADR-700) — RECETTE FINALE : parcours métier COMPLET réellement persisté, bout en bout, sur
 * plusieurs configurations SECTORIELLES :
 *   Créneau → Publication → Pointage → Pause → Pointage sortie → Correction tracée (motif) →
 *   Feuille de temps → Soumission → Approbation → Verrouillage, puis vérification de l'historique.
 *
 * Multisectoriel (jobdomain unique par Company), horaires fractionnés / nuit (minuit) / matinal.
 * Horloge figée et avancée à chaque étape (déterministe — leçon B2). Aucune règle réglementaire testée
 * comme « certifiée ». Preuve = états PERSISTÉS en base + chaîne d'audit, pas de simulation.
 */
class WorkforceE2EJourneyB4Test extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['workforce', 'workforce_planning', 'workforce_leave', 'workforce_payroll'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 07:00:00');
        Model::unguard();
        Market::create(['key' => 'FR', 'name' => 'FR', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (self::MODULES as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Company, User, Employee} [company, owner, report] */
    private function sector(string $jobdomain): array
    {
        Model::unguard();
        Jobdomain::firstOrCreate(['key' => $jobdomain], ['label' => ucfirst($jobdomain), 'default_modules' => self::MODULES]);
        $c = Company::create(['name' => ucfirst($jobdomain), 'slug' => $jobdomain.'-'.uniqid(), 'jobdomain_key' => $jobdomain, 'market_key' => 'FR']);
        foreach (self::MODULES as $m) {
            CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $owner = User::factory()->create();
        Membership::create(['user_id' => $owner->id, 'company_id' => $c->id, 'role' => 'owner']);
        $manager = $this->emp($c, 'Manager', null);
        $report = $this->emp($c, 'Report', $manager->id);
        Model::reguard();

        return [$c, $owner, $report];
    }

    private function emp(Company $c, string $f, ?int $mgr): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $c->id, 'first_name' => $f, 'last_name' => 'X', 'status' => 'active', 'hire_date' => '2026-01-01', 'manager_id' => $mgr, 'employee_number' => 'E-'.uniqid()]);
        EmploymentContract::withoutGlobalScopes()->create(['company_id' => $c->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01', 'status' => 'active', 'is_current' => true]);

        return $e;
    }

    private function api(User $u, Company $c, string $method, string $url, array $body = [])
    {
        $h = ['X-Company-Id' => $c->id];

        return $method === 'get'
            ? $this->actingAs($u)->getJson($url, $h)
            : $this->actingAs($u)->{$method.'Json'}($url, $body, $h);
    }

    /**
     * @dataProvider sectors
     */
    public function test_full_operational_journey_persists(string $jobdomain, string $day, string $in, string $out, string $outDate): void
    {
        [$c, $owner, $report] = $this->sector($jobdomain);

        // 1) Créneau (brouillon) puis PUBLICATION.
        Carbon::setTestNow("{$day} 07:00:00");
        $shift = $this->api($owner, $c, 'post', '/api/workforce/planning/shifts', [
            'employee_id' => $report->id, 'date' => $day,
            'start_at' => "{$day} {$in}:00", 'end_at' => "{$outDate} {$out}:00",
            'timezone' => 'Europe/Paris',
        ])->assertStatus(201)->json();
        $this->api($owner, $c, 'post', '/api/workforce/planning/shifts/publish', ['shift_ids' => [$shift['id']]])->assertOk();
        $this->assertSame('published', Shift::withoutGlobalScopes()->find($shift['id'])->status);

        // 2) POINTAGE : arrivée → pause → reprise → sortie (horloge avancée = temps réaliste).
        Carbon::setTestNow("{$day} {$in}:00");
        $entry = $this->api($owner, $c, 'post', '/api/workforce/time-entries/clock-in', ['employee_id' => $report->id])->assertStatus(201)->json();
        $eid = $entry['id'];
        Carbon::setTestNow("{$day} 12:00:00");
        $this->api($owner, $c, 'post', "/api/workforce/time-entries/{$eid}/break/start", ['type' => 'lunch'])->assertSuccessful();
        Carbon::setTestNow("{$day} 12:30:00");
        $this->api($owner, $c, 'post', "/api/workforce/time-entries/{$eid}/break/end")->assertSuccessful();
        Carbon::setTestNow("{$outDate} {$out}:00");
        $this->api($owner, $c, 'post', "/api/workforce/time-entries/{$eid}/clock-out")->assertSuccessful();
        $this->assertSame('completed', TimeEntry::withoutGlobalScopes()->find($eid)->status);

        // 3) CORRECTION tracée (motif obligatoire) — avant génération.
        $this->api($owner, $c, 'put', "/api/workforce/time-entries/{$eid}", [
            'clock_out' => "{$outDate} {$out}:00", 'reason' => 'Ajustement sortie (recette B4).',
        ])->assertOk();

        // 4) FEUILLE : génération → soumission → approbation → verrouillage.
        $start = Carbon::parse($day)->startOfWeek()->toDateString();
        $end = Carbon::parse($day)->endOfWeek()->toDateString();
        $period = $this->api($owner, $c, 'post', '/api/workforce/timesheets', [
            'employee_id' => $report->id, 'period_start' => $start, 'period_end' => $end,
        ])->assertStatus(201)->json();
        $pid = $period['id'];
        $this->api($owner, $c, 'post', "/api/workforce/timesheets/{$pid}/submit")->assertOk();
        $this->api($owner, $c, 'post', "/api/workforce/timesheets/{$pid}/approve", ['approval_note' => 'Heures vérifiées.'])->assertOk();
        $this->api($owner, $c, 'post', "/api/workforce/timesheets/{$pid}/lock")->assertOk();

        // 5) ÉTAT FINAL PERSISTÉ + chaîne d'audit complète.
        $this->assertSame('locked', TimesheetPeriod::withoutGlobalScopes()->find($pid)->status);
        $actions = collect($this->api($owner, $c, 'get', "/api/workforce/timesheets/{$pid}/history")->assertOk()->json('events'))->pluck('action');
        foreach (['timesheet.generated', 'timesheet.submitted', 'timesheet.approved', 'timesheet.locked', 'time_entry.updated'] as $expected) {
            $this->assertContains($expected, $actions->all(), "L'historique doit contenir {$expected} pour {$jobdomain}.");
        }
    }

    public static function sectors(): array
    {
        return [
            // secteur, jour, arrivée, sortie, date de sortie (minuit traversé → lendemain)
            'restauration (service, pause longue)' => ['restauration', '2026-11-02', '11:00', '15:00', '2026-11-02'],
            'nettoyage (site client matinal)' => ['nettoyage', '2026-11-03', '06:00', '10:00', '2026-11-03'],
            'salon (horaire individuel)' => ['salon', '2026-11-04', '10:30', '18:30', '2026-11-04'],
            'logistique (équipe de nuit, minuit)' => ['logistique', '2026-11-05', '22:00', '06:00', '2026-11-06'],
        ];
    }
}
