<?php

namespace Database\Seeders;

use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use App\Core\Workforce\TimesheetPeriod;
use App\Modules\Workforce\UseCases\ApproveTimesheetUseCase;
use App\Modules\Workforce\UseCases\GenerateTimesheetUseCase;
use App\Modules\Workforce\UseCases\LockTimesheetUseCase;
use App\Modules\Workforce\UseCases\RejectTimesheetUseCase;
use App\Modules\Workforce\UseCases\SubmitTimesheetUseCase;
use App\Modules\Workforce\UseCases\UpdateTimeEntryUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * E3-B / B3 (ADR-699) — feuilles de temps REPRÉSENTATIVES pour la recette des validations (DEV only).
 *
 * S'appuie sur l'équipe B2DEMO de `leezr-logistics` (lancer WorkforceCockpitDemoSeeder d'abord). Pour une
 * PÉRIODE traversant une frontière de mois (2026-10-26 → 2026-11-01), seed des pointages quotidiens puis
 * GÉNÈRE de vraies feuilles via les UseCases réels, et les amène dans des états variés : brouillon,
 * soumise, approuvée (avec note), approuvée+verrouillée, refusée (avec motif). Inclut une correction de
 * pointage tracée (motif) et un poste de nuit traversant minuit → pour l'historique et les écarts.
 *
 * N'altère rien hors B2DEMO. Idempotent (purge les feuilles/pointages B2DEMO de la période avant de régénérer).
 * Respecte les contrats serveur — aucun état/moteur inventé.
 *
 *   php artisan db:seed --class=Database\\Seeders\\WorkforceTimesheetDemoSeeder
 */
class WorkforceTimesheetDemoSeeder extends Seeder
{
    private const PREFIX = 'B2DEMO-';
    private const TZ = 'Europe/Paris';
    private const START = '2026-10-26'; // lundi
    private const END = '2026-11-01';   // dimanche (traverse octobre→novembre)
    private const WORKDAYS = ['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'];

    public function run(): void
    {
        $company = Company::where('slug', 'leezr-logistics')->first();
        if (! $company) {
            $this->command?->warn('WorkforceTimesheetDemoSeeder: company leezr-logistics absente — ignoré.');

            return;
        }

        $employees = DB::table('workforce_employees')
            ->where('company_id', $company->id)
            ->where('employee_number', 'like', self::PREFIX.'%')
            ->orderBy('id')->limit(8)->get();
        if ($employees->count() < 8) {
            $this->command?->warn('WorkforceTimesheetDemoSeeder: équipe B2DEMO introuvable — lancer WorkforceCockpitDemoSeeder d\'abord.');

            return;
        }

        $actorId = Membership::where('company_id', $company->id)->where('role', 'owner')->value('user_id')
            ?? DB::table('workforce_employees')->where('company_id', $company->id)->whereNotNull('user_id')->value('user_id');

        Model::unguard();
        $this->purge($company->id, $employees->pluck('id')->all());

        $gen = app(GenerateTimesheetUseCase::class);
        $submit = app(SubmitTimesheetUseCase::class);
        $approve = app(ApproveTimesheetUseCase::class);
        $reject = app(RejectTimesheetUseCase::class);
        $lock = app(LockTimesheetUseCase::class);
        $correct = app(UpdateTimeEntryUseCase::class);

        // Cible d'état par collaborateur (ordre du tableau = 8 premiers B2DEMO).
        $targets = ['draft', 'submitted', 'approved', 'locked', 'rejected', 'submitted', 'approved', 'locked'];

        foreach ($employees as $i => $emp) {
            // 1) Pointages de la semaine (un poste de nuit le 1er jour pour le collaborateur 3).
            foreach (self::WORKDAYS as $d => $day) {
                $night = ($i === 3 && $d === 0);
                // Collaborateur 0, 1er jour : journée longue 06:00–19:00 (12h) → déclenche un contrôle
                // réglementaire « durée quotidienne maximale » (démo P1a — contrôle du constaté).
                $long = ($i === 0 && $d === 0);
                $in = $night ? '22:00' : ($long ? '06:00' : '09:00');
                $out = $night ? '06:00' : ($long ? '19:00' : '17:00');
                $endDate = $night ? '2026-10-27' : $day;
                $worked = $night ? 480 : ($long ? 720 : 420);
                $this->entry($company->id, $emp->id, $day, $in, $out, $endDate, $worked);
                $this->shift($company->id, $emp->id, $day, $in, $out, $endDate);
            }

            // 2) Correction tracée (motif) pour les collaborateurs 2 et 6 — alimente l'historique.
            if (in_array($i, [2, 6], true)) {
                $entry = TimeEntry::withoutGlobalScopes()
                    ->where('company_id', $company->id)->where('employee_id', $emp->id)
                    ->where('date', self::WORKDAYS[1])->first();
                if ($entry) {
                    try {
                        $correct->execute($entry, ['clock_out' => self::WORKDAYS[1].' 17:30:00', 'reason' => 'Oubli de pointage de sortie — corrigé par le responsable.'], $actorId);
                    } catch (\Throwable $e) { /* best-effort démo */ }
                }
            }

            // 3) Génération de la feuille (brouillon) + transition vers l'état cible.
            try {
                $period = $gen->execute($company->id, $emp->id, self::START, self::END);
                $target = $targets[$i];
                if (in_array($target, ['submitted', 'approved', 'locked', 'rejected'], true)) {
                    $submit->execute($period->fresh(), $actorId);
                }
                if ($target === 'rejected') {
                    $reject->execute($period->fresh(), $actorId, 'Écarts à corriger sur 2 journées — merci de revoir les pointages.');
                }
                if (in_array($target, ['approved', 'locked'], true)) {
                    $approve->execute($period->fresh(), $actorId, 'Heures vérifiées et conformes au planning.');
                }
                if ($target === 'locked') {
                    $lock->execute($period->fresh(), $actorId);
                }
            } catch (\Throwable $e) {
                $this->command?->warn("WorkforceTimesheetDemoSeeder: emp {$emp->id} — ".$e->getMessage());
            }
        }

        Model::reguard();
        $this->command?->info('WorkforceTimesheetDemoSeeder: feuilles '.self::START.'→'.self::END.' (brouillon/soumise/approuvée/verrouillée/refusée) + corrections tracées.');
    }

    private function purge(int $companyId, array $employeeIds): void
    {
        $periodIds = TimesheetPeriod::withoutGlobalScopes()
            ->where('company_id', $companyId)->whereIn('employee_id', $employeeIds)
            ->whereBetween('period_start', [self::START, self::END])->pluck('id');
        if ($periodIds->isNotEmpty()) {
            DB::table('workforce_timesheet_lines')->whereIn('timesheet_period_id', $periodIds)->delete();
            DB::table('workforce_timesheet_periods')->whereIn('id', $periodIds)->delete();
        }
        DB::table('workforce_time_entries')->where('company_id', $companyId)->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [self::START, self::END])->delete();
        DB::table('workforce_shifts')->where('company_id', $companyId)->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [self::START, self::END])->delete();
    }

    private function entry(int $companyId, int $employeeId, string $date, string $in, string $out, string $endDate, int $worked): void
    {
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'employee_id' => $employeeId, 'date' => $date,
            'clock_in' => "{$date} {$in}:00", 'clock_out' => "{$endDate} {$out}:00",
            'status' => 'completed', 'total_worked_minutes' => $worked, 'total_break_minutes' => 60, 'source' => 'manual',
        ]);
    }

    private function shift(int $companyId, int $employeeId, string $date, string $in, string $out, string $endDate): void
    {
        Shift::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'employee_id' => $employeeId, 'date' => $date,
            'start_at' => "{$date} {$in}:00", 'end_at' => "{$endDate} {$out}:00",
            'timezone' => self::TZ, 'status' => 'published', 'published_at' => now(),
            'template_snapshot' => ['break_duration_minutes' => 60],
        ]);
    }
}
