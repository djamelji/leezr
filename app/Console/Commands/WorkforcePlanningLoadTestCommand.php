<?php

namespace App\Console\Commands;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Smart Planning — banc de charge ISOLÉ (DEV uniquement).
 *
 * Crée des companies de test jetables (slug `m6e-load-{N}`) peuplées de données de planning RÉALISTES
 * (collaborateurs, contrats actifs, shifts de la semaine courante, activités, absences, besoins de
 * couverture) afin de MESURER les performances du Smart Planning à 5 / 25 / 50 / 100 collaborateurs,
 * SANS polluer le tenant DEV partagé `leezr-logistics`.
 *
 * ISOLATION STRICTE : chaque action ne touche QUE la company `m6e-load-{N}` (purge par company_id).
 * Aucune company existante n'est jamais lue ni modifiée. GARDE-FOU : refus en environnement production.
 *
 *   php artisan workforce:planning-loadtest seed  --employees=100
 *   php artisan workforce:planning-loadtest purge --employees=100
 *   php artisan workforce:planning-loadtest list
 */
class WorkforcePlanningLoadTestCommand extends Command
{
    protected $signature = 'workforce:planning-loadtest
        {action : seed|purge|list}
        {--employees=25 : Nombre de collaborateurs à générer (slug m6e-load-{N})}
        {--owner=owner@leezr.test : Email du user rattaché comme owner de la company isolée}';

    protected $description = 'Banc de charge Smart Planning : companies de test isolées/purgeables (DEV only)';

    private const SLUG_PREFIX = 'm6e-load-';

    private const TZ = 'Europe/Paris';

    /** Catalogues de shifts (heure murale locale). Night = traverse minuit. */
    private const SHIFT_TEMPLATES = [
        'morning' => ['start' => '06:00', 'end' => '14:00', 'overnight' => false, 'break' => 30, 'break_at' => '10:00'],
        'day' => ['start' => '09:00', 'end' => '17:00', 'overnight' => false, 'break' => 60, 'break_at' => '12:30'],
        'afternoon' => ['start' => '13:00', 'end' => '21:00', 'overnight' => false, 'break' => 30, 'break_at' => '17:00'],
        'night' => ['start' => '22:00', 'end' => '06:00', 'overnight' => true, 'break' => 30, 'break_at' => '02:00'],
    ];

    public function handle(): int
    {
        // GARDE-FOU OBLIGATOIRE — jamais en production.
        if (app()->environment('production')) {
            $this->error('REFUSÉ : workforce:planning-loadtest est interdit en production (DEV only).');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'seed' => $this->seed(),
            'purge' => $this->purge(),
            'list' => $this->list(),
            default => $this->invalidAction(),
        };
    }

    private function invalidAction(): int
    {
        $this->error("Action inconnue. Utiliser : seed | purge | list.");

        return self::FAILURE;
    }

    private function employeesOption(): int
    {
        return max(1, (int) $this->option('employees'));
    }

    private function slug(): string
    {
        return self::SLUG_PREFIX.$this->employeesOption();
    }

    // ───────────────────────────────────────────────────────────── LIST ──

    private function list(): int
    {
        $rows = DB::table('companies')
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->orderBy('id')
            ->get(['id', 'slug', 'name']);

        if ($rows->isEmpty()) {
            $this->info('Aucune company de banc de charge (m6e-load-*).');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Slug', 'Nom', 'Collaborateurs'],
            $rows->map(fn ($c) => [
                $c->id,
                $c->slug,
                $c->name,
                DB::table('workforce_employees')->where('company_id', $c->id)->count(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────── PURGE ──

    private function purge(): int
    {
        $slug = $this->slug();
        $company = DB::table('companies')->where('slug', $slug)->first();

        if (! $company) {
            $this->info("Rien à purger : company '{$slug}' absente (idempotent).");

            return self::SUCCESS;
        }

        $this->purgeCompanyById((int) $company->id);
        $this->info("Company '{$slug}' (id={$company->id}) et toutes ses données supprimées.");

        return self::SUCCESS;
    }

    /** Supprime TOUTES les données workforce d'une company puis la company — par company_id uniquement. */
    private function purgeCompanyById(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            // Dépendances enfants d'abord (respect des FK), puis racines.
            DB::table('workforce_scheduled_activities')->where('company_id', $companyId)->delete();
            DB::table('workforce_leave_requests')->where('company_id', $companyId)->delete();
            DB::table('workforce_shifts')->where('company_id', $companyId)->delete();
            DB::table('workforce_coverage_requirements')->where('company_id', $companyId)->delete();
            DB::table('workforce_coverage_categories')->where('company_id', $companyId)->delete();
            DB::table('workforce_employment_contracts')->where('company_id', $companyId)->delete();
            DB::table('workforce_leave_types')->where('company_id', $companyId)->delete();

            // Casser les auto-références avant suppression (manager_id employé & département).
            DB::table('workforce_departments')->where('company_id', $companyId)->update(['manager_id' => null]);
            DB::table('workforce_employees')->where('company_id', $companyId)->update(['manager_id' => null]);
            DB::table('workforce_employees')->where('company_id', $companyId)->delete();
            DB::table('workforce_departments')->where('company_id', $companyId)->delete();
            DB::table('workforce_work_locations')->where('company_id', $companyId)->delete();

            DB::table('company_jobdomain')->where('company_id', $companyId)->delete();
            DB::table('memberships')->where('company_id', $companyId)->delete();
            DB::table('companies')->where('id', $companyId)->delete();
        });
    }

    // ───────────────────────────────────────────────────────────── SEED ──

    private function seed(): int
    {
        $n = $this->employeesOption();
        $slug = $this->slug();

        // Recréation idempotente : on repart d'un état propre.
        $existing = DB::table('companies')->where('slug', $slug)->first();
        if ($existing) {
            $this->purgeCompanyById((int) $existing->id);
        }

        Model::unguard();

        // Socle structurel (comme les tests) : jobdomain + market doivent exister.
        $jobdomain = Jobdomain::firstOrCreate(
            ['key' => 'logistique'],
            ['label' => 'Logistique', 'default_modules' => [], 'is_active' => true],
        );
        Market::firstOrCreate(
            ['key' => 'FR'],
            ['name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => self::TZ,
                'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => ''],
        );

        $recap = DB::transaction(function () use ($n, $slug, $jobdomain) {
            // 1) Company isolée.
            $company = Company::create([
                'name' => "[M6E LOAD] {$n} collaborateurs",
                'slug' => $slug,
                'status' => 'active',
                'plan_key' => 'pro',
                'market_key' => 'FR',
                'jobdomain_key' => 'logistique',
                'address_country_code' => 'FR',
                'week_start_day' => 1, // lundi — semaine déterministe
            ]);
            $company->jobdomains()->syncWithoutDetaching([$jobdomain->id]);
            $cid = $company->id;

            // Types de congés (requis : leave_type_id NOT NULL sur les absences).
            LeaveTypeSeeder::seedForCompany($cid, 'FR');
            $leaveTypeId = (int) DB::table('workforce_leave_types')
                ->where('company_id', $cid)->where('code', 'annual_leave')->value('id');

            // 2) Lieux (2 internes + 1 site client).
            $locations = $this->seedLocations($cid);

            // 3) Départements (~max(2, ceil(N/15))) + répartition.
            $deptCount = max(2, (int) ceil($n / 15));
            $departments = $this->seedDepartments($cid, $deptCount);

            // 4) Collaborateurs + contrats CDI actifs ; 1er de chaque équipe = manager.
            [$employees, $contracts] = $this->seedEmployeesAndContracts($cid, $n, $departments);

            // 5) Shifts de la semaine courante (mix draft/published, horaires variés, nuits).
            $shiftCount = $this->seedShifts($cid, $employees, $locations);

            // 6) Activités planifiées sur ~20% des shifts.
            $activityCount = $this->seedActivities($cid);

            // 7) Catégories + besoins de couverture (couverts ET non couverts).
            [$catCount, $reqCount] = $this->seedCoverage($cid, $departments, $locations);

            // 8) Absences sur ~10% des collaborateurs (jour entier approved + partielles).
            $leaveCount = $this->seedLeaves($cid, $employees, $leaveTypeId);

            return [
                'company_id' => $cid,
                'employees' => count($employees),
                'contracts' => $contracts,
                'shifts' => $shiftCount,
                'activities' => $activityCount,
                'leaves' => $leaveCount,
                'coverage_categories' => $catCount,
                'coverage_requirements' => $reqCount,
                'departments' => count($departments),
                'locations' => count($locations),
            ];
        });

        // 9) Owner (hors transaction : échec non bloquant pour la company).
        $this->attachOwner((int) $recap['company_id']);

        Model::reguard();

        $this->info("Company '{$slug}' seedée — id={$recap['company_id']}");
        $this->table(['Entité', 'Volume'], [
            ['Collaborateurs', $recap['employees']],
            ['Contrats CDI actifs', $recap['contracts']],
            ['Départements', $recap['departments']],
            ['Lieux', $recap['locations']],
            ['Shifts (semaine courante)', $recap['shifts']],
            ['Activités planifiées', $recap['activities']],
            ['Absences', $recap['leaves']],
            ['Catégories de couverture', $recap['coverage_categories']],
            ['Besoins de couverture', $recap['coverage_requirements']],
        ]);

        return self::SUCCESS;
    }

    // ───────────────────────────────────────────────── seed helpers ──

    /** @return array<int,int> ids des lieux */
    private function seedLocations(int $cid): array
    {
        $now = now();
        $defs = [
            ['name' => 'Entrepôt Central', 'type' => 'internal', 'client_name' => null],
            ['name' => 'Plateforme Logistique Sud', 'type' => 'internal', 'client_name' => null],
            ['name' => 'Site client Carrefour', 'type' => 'client_site', 'client_name' => 'Carrefour SA'],
        ];
        $ids = [];
        foreach ($defs as $i => $d) {
            $ids[] = DB::table('workforce_work_locations')->insertGetId([
                'company_id' => $cid, 'name' => $d['name'], 'type' => $d['type'], 'client_name' => $d['client_name'],
                'timezone' => self::TZ, 'enabled' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /** @return array<int,int> ids des départements */
    private function seedDepartments(int $cid, int $count): array
    {
        $now = now();
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = DB::table('workforce_departments')->insertGetId([
                'company_id' => $cid, 'name' => 'Équipe '.($i + 1), 'parent_id' => null, 'manager_id' => null,
                'sort_order' => $i, 'source' => 'company', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * Crée N employés répartis en équipes ; le 1er de chaque équipe devient manager des autres.
     *
     * @param  array<int,int>  $departments
     * @return array{0: array<int,array{id:int,dept:int}>, 1: int}
     */
    private function seedEmployeesAndContracts(int $cid, int $n, array $departments): array
    {
        $now = now();
        $firsts = ['Camille', 'Yanis', 'Fatou', 'Hugo', 'Léa', 'Marc', 'Sophie', 'Karim', 'Julie', 'Thomas',
            'Nadia', 'Paul', 'Emma', 'Antoine', 'Chloé', 'Mehdi', 'Clara', 'Lucas', 'Inès', 'Noah'];
        $lasts = ['Laurent', 'Moreau', 'Ndiaye', 'Petit', 'Garnier', 'Olivier', 'Bernard', 'Haddad', 'Rousseau',
            'Girard', 'Benali', 'Lefebvre', 'Fontaine', 'Mercier', 'Dubois', 'Saïd', 'Vidal', 'Blanc', 'Robert', 'Faure'];

        $deptCount = count($departments);
        $employees = [];          // [i => ['id'=>, 'dept'=>deptIndex]]
        $firstOfDept = [];        // deptIndex => employee id (le manager)
        $contractRows = [];

        for ($i = 0; $i < $n; $i++) {
            $deptIndex = $i % $deptCount;
            $first = $firsts[$i % count($firsts)];
            $last = $lasts[($i * 3) % count($lasts)];

            $empId = DB::table('workforce_employees')->insertGetId([
                'company_id' => $cid,
                'employee_number' => 'M6E-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'first_name' => $first, 'last_name' => $last.' '.($i + 1),
                'status' => 'active', 'hire_date' => '2025-01-06',
                'department_id' => $departments[$deptIndex], 'manager_id' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $employees[$i] = ['id' => $empId, 'dept' => $deptIndex];
            if (! isset($firstOfDept[$deptIndex])) {
                $firstOfDept[$deptIndex] = $empId; // chef d'équipe
            }

            // 1 CDI actif par collaborateur (début passé, sans fin).
            $contractRows[] = [
                'company_id' => $cid, 'employee_id' => $empId, 'contract_type' => 'cdi',
                'work_model_key' => 'horaire', 'weekly_hours' => 35, 'start_date' => '2025-01-06',
                'status' => 'active', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // Contrats en insertion groupée.
        foreach (array_chunk($contractRows, 200) as $chunk) {
            DB::table('workforce_employment_contracts')->insert($chunk);
        }

        // Rattachement manager : chaque employé non-chef pointe vers le chef de son équipe.
        foreach ($employees as $emp) {
            $managerId = $firstOfDept[$emp['dept']];
            if ($managerId !== $emp['id']) {
                DB::table('workforce_employees')->where('id', $emp['id'])->update(['manager_id' => $managerId]);
            }
        }
        // Le chef d'équipe manage son département.
        foreach ($firstOfDept as $deptIndex => $managerId) {
            DB::table('workforce_departments')->where('id', $departments[$deptIndex])->update(['manager_id' => $managerId]);
        }

        return [$employees, count($contractRows)];
    }

    /**
     * Shifts de la semaine courante (lundi→dimanche), ~4-5/employé, mix draft(~40%)/published(~60%),
     * horaires variés + quelques nuits traversant minuit. Insertion groupée.
     *
     * @param  array<int,array{id:int,dept:int}>  $employees
     * @param  array<int,int>  $locations
     */
    private function seedShifts(int $cid, array $employees, array $locations): int
    {
        $now = now();
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $templateCycle = ['day', 'morning', 'afternoon']; // rotation d'horaires
        $catKeys = array_keys(self::SHIFT_TEMPLATES);

        $rows = [];
        foreach ($employees as $i => $emp) {
            $numShifts = ($i % 2 === 0) ? 4 : 5;
            $startDay = $i % 3; // décale le début de semaine → variété + week-ends couverts
            for ($s = 0; $s < $numShifts; $s++) {
                $dayOffset = min(6, $startDay + $s);
                $date = $weekStart->copy()->addDays($dayOffset);

                // Un collaborateur sur ~6 fait un poste de NUIT sur son dernier shift.
                $isNight = ($i % 6 === 0) && ($s === $numShifts - 1);
                $tplKey = $isNight ? 'night' : $templateCycle[($i + $s) % count($templateCycle)];
                $tpl = self::SHIFT_TEMPLATES[$tplKey];

                $dateStr = $date->toDateString();
                $endDateStr = $tpl['overnight'] ? $date->copy()->addDay()->toDateString() : $dateStr;

                // ~60% published / ~40% draft.
                $published = (($i * 7 + $s) % 5) < 3;

                // template_snapshot : pause (total + fenêtre) + catégorie de couverture sur une partie.
                $breakEnd = Carbon::parse('2000-01-01 '.$tpl['break_at'])->addMinutes($tpl['break'])->format('H:i');
                $snapshot = [
                    'break_duration_minutes' => $tpl['break'],
                    'break_windows' => [['start' => $tpl['break_at'], 'end' => $breakEnd]],
                ];
                // Catégorie de couverture renseignée dans le snapshot sur ~la moitié des shifts
                // (le FK réel est posé via seedCoverage ; ici c'est un marqueur de contribution).
                if (($i + $s) % 2 === 0) {
                    $snapshot['coverage_category_code'] = $catKeys[($i + $s) % count($catKeys)];
                }

                $rows[] = [
                    'company_id' => $cid, 'employee_id' => $emp['id'],
                    'work_location_id' => $locations[($i + $s) % count($locations)],
                    'template_snapshot' => json_encode($snapshot),
                    'date' => $dateStr,
                    'start_at' => $dateStr.' '.$tpl['start'].':00',
                    'end_at' => $endDateStr.' '.$tpl['end'].':00',
                    'timezone' => self::TZ,
                    'status' => $published ? 'published' : 'draft',
                    'published_at' => $published ? $now : null,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('workforce_shifts')->insert($chunk);
        }

        return count($rows);
    }

    /** Active ~20% des shifts avec 1-2 activités planifiées. Rejoue les ids réels fraîchement insérés. */
    private function seedActivities(int $cid): int
    {
        $now = now();
        $catCode = null; // les activités réutilisent la catégorie du snapshot si présente
        $shifts = DB::table('workforce_shifts')
            ->where('company_id', $cid)
            ->orderBy('id')
            ->get(['id', 'employee_id', 'work_location_id', 'start_at', 'end_at', 'template_snapshot']);

        // Map code catégorie -> id (posées par seedCoverage avant cet appel ? non : appelé après seedShifts,
        // avant seedCoverage). On laisse coverage_category_id NULL ici (nullable) — la contribution métier
        // des activités au besoin passe par le shift. Label explicite pour la lisibilité recette.
        $labels = ['Préparation commande', 'Chargement camion', 'Contrôle qualité', 'Tournée livraison', 'Inventaire'];

        $rows = [];
        $idx = 0;
        foreach ($shifts as $shift) {
            if ($idx++ % 5 !== 0) { // ~20%
                continue;
            }
            $start = Carbon::parse($shift->start_at);
            $end = Carbon::parse($shift->end_at);
            $spanMin = max(60, (int) $start->diffInMinutes($end));

            // 1 à 2 activités successives bornées dans le shift.
            $nbAct = ($idx % 10 === 1) ? 2 : 1;
            for ($a = 0; $a < $nbAct; $a++) {
                $aStart = $start->copy()->addMinutes((int) ($spanMin * $a / ($nbAct + 1)));
                $aEnd = $start->copy()->addMinutes((int) ($spanMin * ($a + 1) / ($nbAct + 1)));
                $rows[] = [
                    'company_id' => $cid, 'shift_id' => $shift->id, 'employee_id' => $shift->employee_id,
                    'work_location_id' => $shift->work_location_id,
                    'activity_type' => 'task', 'coverage_category_id' => null,
                    'label' => $labels[($idx + $a) % count($labels)],
                    'start_at' => $aStart->format('Y-m-d H:i:s'), 'end_at' => $aEnd->format('Y-m-d H:i:s'),
                    'status' => 'planned', 'priority' => 'normal', 'source' => 'manual',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('workforce_scheduled_activities')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * Catégories de couverture + 1 besoin/jour ouvré/catégorie (récurrent day_of_week 1→5).
     * Headcount réaliste → certains besoins couverts, d'autres non (vue Postes).
     *
     * @param  array<int,int>  $departments
     * @param  array<int,int>  $locations
     * @return array{0:int,1:int}
     */
    private function seedCoverage(int $cid, array $departments, array $locations): array
    {
        $now = now();
        $cats = [
            ['code' => 'ROUTE', 'name' => 'Tournée', 'color' => '#2563eb'],
            ['code' => 'PREP', 'name' => 'Préparation', 'color' => '#16a34a'],
            ['code' => 'RECEP', 'name' => 'Réception', 'color' => '#ea580c'],
        ];
        $catIds = [];
        foreach ($cats as $i => $c) {
            $catIds[] = DB::table('workforce_coverage_categories')->insertGetId([
                'company_id' => $cid, 'code' => $c['code'], 'name' => $c['name'], 'color' => $c['color'],
                'resource_type' => 'employee', 'enabled' => true, 'sort_order' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $rows = [];
        // Besoins récurrents du lundi (1) au vendredi (5) pour chaque catégorie.
        foreach ($catIds as $ci => $catId) {
            for ($dow = 1; $dow <= 5; $dow++) {
                $rows[] = [
                    'company_id' => $cid, 'label' => $cats[$ci]['name'].' — J'.$dow,
                    'date' => null, 'day_of_week' => $dow,
                    'start_time' => '09:00:00', 'end_time' => '17:00:00',
                    'department_id' => $departments[$ci % count($departments)],
                    'work_location_id' => $locations[$ci % count($locations)],
                    'activity_type' => null, 'coverage_category_id' => $catId,
                    'headcount' => 2 + $ci, // 2..4 → mix couvert/non couvert
                    'priority' => 'normal', 'source' => 'manual', 'enabled' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('workforce_coverage_requirements')->insert($chunk);
        }

        return [count($catIds), count($rows)];
    }

    /**
     * Absences sur ~10% des collaborateurs : jour entier approved + une partielle (fenêtre horaire),
     * posées sur la semaine courante (opposables au Smart Planning via absentWindowOn).
     *
     * @param  array<int,array{id:int,dept:int}>  $employees
     */
    private function seedLeaves(int $cid, array $employees, int $leaveTypeId): int
    {
        $now = now();
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $rows = [];
        $partialToggle = false;

        foreach ($employees as $i => $emp) {
            if ($i % 10 !== 0) { // ~10%
                continue;
            }
            $day = $weekStart->copy()->addDays(2 + ($i % 3)); // mercredi..vendredi
            if ($partialToggle) {
                // Absence partielle (après-midi) : 4h.
                $rows[] = [
                    'company_id' => $cid, 'employee_id' => $emp['id'], 'leave_type_id' => $leaveTypeId,
                    'date_from' => $day->toDateString(), 'date_to' => $day->toDateString(),
                    'start_time' => '14:00:00', 'end_time' => '18:00:00',
                    'days_count_hundredths' => 0, 'hours_count_hundredths' => 400,
                    'status' => 'approved', 'reason' => 'Rendez-vous (banc de charge)',
                    'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            } else {
                // Absence jour entier.
                $rows[] = [
                    'company_id' => $cid, 'employee_id' => $emp['id'], 'leave_type_id' => $leaveTypeId,
                    'date_from' => $day->toDateString(), 'date_to' => $day->toDateString(),
                    'start_time' => null, 'end_time' => null,
                    'days_count_hundredths' => 100, 'hours_count_hundredths' => null,
                    'status' => 'approved', 'reason' => 'Congé (banc de charge)',
                    'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $partialToggle = ! $partialToggle;
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('workforce_leave_requests')->insert($chunk);
        }

        return count($rows);
    }

    /** Rattache l'owner demandé (si trouvé) comme membership owner. Non bloquant. */
    private function attachOwner(int $companyId): void
    {
        $email = (string) $this->option('owner');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->warn("Owner '{$email}' introuvable — company créée sans membership owner. "
                .'Créez le user ou passez --owner=<email> existant pour la recette Playwright.');

            return;
        }

        DB::table('memberships')->updateOrInsert(
            ['company_id' => $companyId, 'user_id' => $user->id],
            ['role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
        );
        $this->info("Owner rattaché : {$email} (user id={$user->id}) → membership owner de la company id={$companyId}.");
    }
}
