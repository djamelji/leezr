<?php

namespace Database\Seeders;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Shift;
use App\Core\Workforce\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E3-B / B2 (ADR-698) — données de pointage REPRÉSENTATIVES pour la recette du cockpit (DEV uniquement).
 *
 * Cible la Company démo `leezr-logistics` (comme WorkforceDemoSeeder). Crée une ÉQUIPE DÉDIÉE et ISOLÉE
 * (20 collaborateurs préfixés `B2DEMO-`, arbre manager, 2 lieux) avec des shifts (prévu) et des pointages
 * (réel) du JOUR couvrant tous les états du cockpit : en poste, en pause, terminé avec écart +/−, non
 * pointé malgré un shift, présence imprévue (sans shift), anomalies (pause manquante / heures
 * excessives / zéro travaillé), poste de nuit traversant minuit ; + un pointage INCOMPLET la veille.
 *
 * N'altère AUCUN enregistrement existant : purge/recrée uniquement les lignes `B2DEMO-`. Idempotent.
 * Respecte les contraintes modèles (timezone requis, template_snapshot.break_duration_minutes,
 * clock_in < clock_out, statuts/sources valides). Aucune spécificité secteur (générique horizontal).
 *
 *   php artisan db:seed --class=Database\\Seeders\\WorkforceCockpitDemoSeeder
 */
class WorkforceCockpitDemoSeeder extends Seeder
{
    private const PREFIX = 'B2DEMO-';
    private const TZ = 'Europe/Paris';

    public function run(): void
    {
        $company = Company::where('slug', 'leezr-logistics')->first();
        if (! $company) {
            $this->command?->warn('WorkforceCockpitDemoSeeder: company leezr-logistics absente — ignoré.');

            return;
        }

        Model::unguard();
        $this->purge($company->id);

        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::today()->subDay()->toDateString();
        $tomorrow = Carbon::today()->addDay()->toDateString();

        // Lieux de travail (plusieurs lieux — §7).
        $locNord = DB::table('workforce_work_locations')->insertGetId([
            'company_id' => $company->id, 'name' => self::PREFIX.'Entrepôt Nord', 'type' => 'internal',
            'timezone' => self::TZ, 'enabled' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locClient = DB::table('workforce_work_locations')->insertGetId([
            'company_id' => $company->id, 'name' => self::PREFIX.'Site client Dupont', 'type' => 'client_site',
            'client_name' => 'Dupont SARL', 'timezone' => self::TZ, 'enabled' => true, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $names = [
            'Camille Laurent', 'Yanis Moreau', 'Fatou Ndiaye', 'Hugo Petit', 'Léa Garnier',
            'Marc Olivier', 'Sophie Bernard', 'Karim Haddad', 'Julie Rousseau', 'Thomas Girard',
            'Nadia Benali', 'Paul Lefebvre', 'Emma Fontaine', 'Antoine Mercier', 'Chloé Dubois',
            'Mehdi Saïd', 'Clara Vidal', 'Lucas Blanc', 'Inès Robert', 'Noah Faure',
        ];

        // 1) Collaborateurs + contrats. Le 1er est le manager des 4 suivants (démo du scope).
        $emps = [];
        foreach ($names as $i => $full) {
            [$first, $last] = array_pad(explode(' ', $full, 2), 2, '');
            $emps[$i] = Employee::withoutGlobalScopes()->create([
                'company_id' => $company->id, 'first_name' => $first, 'last_name' => $last,
                'status' => 'active', 'hire_date' => '2026-01-01',
                'manager_id' => ($i >= 1 && $i <= 4) ? null : null, // renseigné juste après (le manager n'existe pas encore pour i=0)
                'employee_number' => self::PREFIX.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            ]);
            EmploymentContract::withoutGlobalScopes()->create([
                'company_id' => $company->id, 'employee_id' => $emps[$i]->id, 'contract_type' => 'cdi',
                'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2026-01-01',
                'status' => 'active', 'is_current' => true,
            ]);
        }
        foreach ([1, 2, 3, 4] as $i) {
            $emps[$i]->update(['manager_id' => $emps[0]->id]);
        }

        // 2) États du JOUR (prévu = shift / réel = pointage). planned = durée − pause(60) = 420 pour un 09–17.
        $plan = [
            // [idx, shift?[in,out,loc,endDate], entry?[in,out,status,worked,break]]
            [0,  ['09:00', '17:00', $locNord, null],   ['09:00', '17:00', 'completed', 420, 60]],   // matched, écart 0 (manager)
            [1,  ['08:00', '16:00', $locNord, null],   ['08:02', null,    'working',   null, 0]],    // en poste
            [2,  ['09:00', '17:00', $locNord, null],   ['09:00', null,    'on_break',  null, 30]],   // en pause
            [3,  ['09:00', '17:00', $locClient, null], ['09:10', '16:00', 'completed', 360, 60]],    // parti plus tôt → écart −60
            [4,  ['09:00', '17:00', $locClient, null], ['08:55', '18:00', 'completed', 480, 60]],    // dépassement → écart +60
            [5,  ['14:00', '22:00', $locClient, null], null],                                        // non pointé (shift sans pointage)
            [6,  null,                                 ['10:00', '14:00', 'completed', 240, 0]],      // présence imprévue (sans shift)
            [7,  ['09:00', '17:00', $locNord, null],   ['09:00', '16:20', 'completed', 400, 0]],      // anomalie : pause manquante
            [8,  ['08:00', '20:00', $locNord, null],   ['08:00', '19:30', 'completed', 660, 60]],     // anomalie : heures excessives
            [9,  ['22:00', '06:00', $locNord, $tomorrow], ['22:05', null, 'working',   null, 0]],     // poste de NUIT (traverse minuit)
            [10, ['09:00', '17:00', $locNord, null],   ['09:00', '17:00', 'completed', 420, 60]],
            [11, ['09:00', '17:00', $locNord, null],   ['09:00', '17:00', 'completed', 420, 60]],
            [12, ['09:00', '17:00', $locClient, null], ['09:00', '17:00', 'completed', 420, 60]],
            [13, ['09:00', '17:00', $locClient, null], ['09:00', '17:05', 'completed', 425, 60]],
            [14, ['09:00', '17:00', $locNord, null],   ['08:50', '17:00', 'completed', 430, 60]],
            [15, ['13:00', '21:00', $locClient, null], null],                                         // équipe après-midi non encore arrivée
            [16, ['13:00', '21:00', $locClient, null], null],
            [17, ['06:00', '14:00', $locNord, null],   null],                                         // équipe matinale non pointée
            [18, ['09:00', '17:00', $locNord, null],   ['09:00', '09:00', 'completed', 0,   0]],      // anomalie : zéro travaillé
            [19, ['09:00', '17:00', $locNord, null],   ['09:00', '17:00', 'completed', 420, 60]],
        ];

        foreach ($plan as [$idx, $shift, $entry]) {
            $e = $emps[$idx];
            if ($shift) {
                [$in, $out, $loc, $endDate] = $shift;
                $this->shift($company->id, $e->id, $today, $in, $out, $loc, $endDate);
            }
            if ($entry) {
                [$in, $out, $status, $worked, $break] = $entry;
                $this->entry($company->id, $e->id, $today, $in, $out, $status, $worked, $break);
            }
        }

        // 3) La VEILLE : un pointage INCOMPLET (entrée sans sortie sur un jour passé) — état « incomplet ».
        $this->shift($company->id, $emps[1]->id, $yesterday, '08:00', '16:00', $locNord, null);
        $this->entry($company->id, $emps[1]->id, $yesterday, '08:00', null, 'working', null, 0);

        Model::reguard();
        $this->command?->info('WorkforceCockpitDemoSeeder: 20 collaborateurs B2DEMO + shifts/pointages du '.$today.' (cockpit représentatif).');
    }

    private function purge(int $companyId): void
    {
        $ids = DB::table('workforce_employees')->where('company_id', $companyId)
            ->where('employee_number', 'like', self::PREFIX.'%')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('workforce_time_entry_breaks')->whereIn('time_entry_id',
                DB::table('workforce_time_entries')->whereIn('employee_id', $ids)->pluck('id'))->delete();
            DB::table('workforce_time_entries')->whereIn('employee_id', $ids)->delete();
            DB::table('workforce_shifts')->whereIn('employee_id', $ids)->delete();
            DB::table('workforce_employment_contracts')->whereIn('employee_id', $ids)->delete();
            DB::table('workforce_employees')->whereIn('id', $ids)->delete();
        }
        DB::table('workforce_work_locations')->where('company_id', $companyId)
            ->where('name', 'like', self::PREFIX.'%')->delete();
    }

    private function shift(int $companyId, int $employeeId, string $date, string $in, string $out, ?int $locationId, ?string $endDate): void
    {
        Shift::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'employee_id' => $employeeId, 'date' => $date,
            'start_at' => "{$date} {$in}:00", 'end_at' => ($endDate ?? $date)." {$out}:00",
            'timezone' => self::TZ, 'status' => 'published', 'work_location_id' => $locationId,
            'published_at' => now(), 'template_snapshot' => ['break_duration_minutes' => 60],
        ]);
    }

    private function entry(int $companyId, int $employeeId, string $date, ?string $in, ?string $out, string $status, ?int $worked, int $break): void
    {
        TimeEntry::withoutGlobalScopes()->create([
            'company_id' => $companyId, 'employee_id' => $employeeId, 'date' => $date,
            'clock_in' => $in ? "{$date} {$in}:00" : null,
            'clock_out' => $out ? "{$date} {$out}:00" : null,
            'status' => $status, 'total_worked_minutes' => $worked, 'total_break_minutes' => $break, 'source' => 'manual',
        ]);
    }
}
