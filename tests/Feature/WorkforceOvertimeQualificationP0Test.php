<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetLine;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\ReadModels\TimesheetAnomalyReadModel;
use App\Modules\Workforce\UseCases\GenerateTimesheetUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P0 (ADR-701) — QUALIFICATION des heures supplémentaires : une heure sup est un CONSTAT, pas une
 * anomalie technique. Vérifie que les minutes sont conservées intégralement, qu'elles ne polluent plus
 * le compteur d'anomalies (ni le read model), que les vraies anomalies demeurent, qu'il n'y a pas de
 * double comptage jour/semaine, et que les feuilles HISTORIQUES ne sont pas mutées silencieusement.
 * AUCUN moteur de calcul n'est modifié (les quantités sont inchangées).
 */
class WorkforceOvertimeQualificationP0Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        $this->seed(\Database\Seeders\MarketRuleSetSeeder::class);
        Model::unguard();
        $this->company = Company::create(['name' => 'OT Co', 'slug' => 'ot-co', 'jobdomain_key' => 'logistique', 'plan_key' => 'pro', 'market_key' => 'FR']);
        $this->employee = Employee::create(['company_id' => $this->company->id, 'first_name' => 'O', 'last_name' => 'T', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(string $date, int $worked = 480, int $break = 60): void
    {
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'date' => $date,
            'clock_in' => "{$date} 08:00:00", 'clock_out' => "{$date} 17:00:00",
            'status' => TimeEntry::STATUS_COMPLETED, 'total_worked_minutes' => $worked, 'total_break_minutes' => $break, 'source' => 'manual',
        ]);
        // Créneau planifié correspondant → pas de "unplanned_work" ni late/early : scénario HS LÉGITIME propre.
        \App\Core\Workforce\Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'date' => $date,
            'start_at' => "{$date} 08:00:00", 'end_at' => "{$date} 17:00:00", 'timezone' => 'Europe/Paris',
            'status' => 'published', 'template_snapshot' => ['break_duration_minutes' => 60],
        ]);
    }

    public function test_overtime_is_preserved_but_not_an_anomaly(): void
    {
        // 5 jours × 8h (480') = 2400' > 35h (2100') → HS journalières (480-420=60/j) + HS hebdo (300').
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $d) {
            $this->entry($d);
        }
        $period = app(GenerateTimesheetUseCase::class)->execute($this->company->id, $this->employee->id, '2026-11-02', '2026-11-08');

        // Quantités CONSERVÉES (colonnes) — INCHANGÉES par P0 (le calcul moteur n'est pas touché).
        // Seuil journalier effectif = 6h (360') → 480-360 = 120/j × 5 = 600 (comportement moteur existant).
        $this->assertSame(600, $period->total_overtime_minutes, 'HS journalières cumulées préservées.');
        foreach ($period->lines->where('worked_minutes', '>', 0) as $line) {
            $this->assertSame(120, $line->daily_overtime_minutes);
        }
        // Aucune heure sup écrite dans les anomalies (toutes lignes).
        foreach ($period->lines as $line) {
            foreach ($line->anomalies ?? [] as $a) {
                $this->assertNotContains($a['type'], TimesheetLine::OVERTIME_ANOMALY_TYPES);
            }
        }
        // HS hebdomadaires conservées en metadata (dernier jour), DISTINCTES des journalières (pas de somme).
        $weekly = collect($period->lines)->sum(fn ($l) => $l->metadata['weekly_overtime_minutes'] ?? 0);
        $expectedWeekly = max(0, $period->lines->sum('worked_minutes') - 35 * 60);
        $this->assertSame($expectedWeekly, $weekly, 'HS hebdo = travaillé - seuil 35h, stockée en metadata.');
        $this->assertGreaterThan(0, $weekly);

        // Le compteur d'anomalies n'est PAS pollué par les heures sup.
        $this->assertSame(0, $period->anomaly_count);

        // Read model : aucune clé heure sup dans le groupement d'anomalies.
        $grouped = TimesheetAnomalyReadModel::forPeriod($period->id);
        $this->assertArrayNotHasKey('overtime_daily', $grouped);
        $this->assertArrayNotHasKey('overtime_weekly', $grouped);
    }

    public function test_real_technical_anomaly_still_counts(): void
    {
        // Ligne mixte (héritée/historique) : une HS + une vraie anomalie technique.
        $period = TimesheetPeriod::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'period_start' => '2026-11-02', 'period_end' => '2026-11-08', 'status' => 'draft']);
        TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'timesheet_period_id' => $period->id, 'employee_id' => $this->employee->id,
            'date' => '2026-11-02', 'worked_minutes' => 480, 'break_minutes' => 60, 'planned_minutes' => 420, 'daily_overtime_minutes' => 60,
            'anomalies' => [
                ['type' => TimesheetLine::ANOMALY_OVERTIME_DAILY, 'severity' => 'warning', 'details' => ['excess' => 60]],
                ['type' => TimesheetLine::ANOMALY_MISSING_CLOCK_OUT, 'severity' => 'warning', 'details' => []],
            ],
            'source_snapshot' => [],
        ]);

        $period->recomputeTotals();
        $period->save();

        // Seule l'anomalie technique compte (heure sup exclue).
        $this->assertSame(1, $period->fresh()->anomaly_count);
        $grouped = TimesheetAnomalyReadModel::forPeriod($period->id);
        $this->assertArrayHasKey('missing_clock_out', $grouped);
        $this->assertArrayNotHasKey('overtime_daily', $grouped);
    }

    public function test_historical_locked_sheet_is_not_mutated(): void
    {
        // Feuille VERROUILLÉE historique avec une HS comptée dans anomaly_count : NON mutée en silence.
        $period = TimesheetPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'period_start' => '2026-10-05', 'period_end' => '2026-10-11', 'status' => 'locked',
            'total_overtime_minutes' => 60, 'anomaly_count' => 1,
        ]);
        TimesheetLine::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'timesheet_period_id' => $period->id, 'employee_id' => $this->employee->id,
            'date' => '2026-10-05', 'worked_minutes' => 480, 'break_minutes' => 60, 'planned_minutes' => 420, 'daily_overtime_minutes' => 60,
            'anomalies' => [['type' => TimesheetLine::ANOMALY_OVERTIME_DAILY, 'severity' => 'warning', 'details' => ['excess' => 60]]],
            'source_snapshot' => [],
        ]);

        // Stocké : la valeur historique reste figée (aucun recompute sur une feuille verrouillée).
        $this->assertSame(1, $period->fresh()->anomaly_count, 'anomaly_count historique inchangé (pas de mutation silencieuse).');
        // Affichage : le read model reclasse quand même → plus d'heure sup dans les anomalies techniques.
        $this->assertArrayNotHasKey('overtime_daily', TimesheetAnomalyReadModel::forPeriod($period->id));
    }
}
