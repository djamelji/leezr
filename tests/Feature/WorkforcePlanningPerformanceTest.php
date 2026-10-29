<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\PlanningBoardReadModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * W-G4A (ADR-644) — performance & absence de N+1 du tableau Planning sur fixtures volumineuses
 * (100 et 500 collaborateurs, ~5 shifts/semaine chacun). Le nombre de requêtes doit rester CONSTANT
 * (indépendant du volume) grâce à l'eager-load. Les temps mesurés sont rapportés (non inventés).
 */
class WorkforcePlanningPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->company = Company::create(['name' => 'Perf Co', 'slug' => 'perf-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
    }

    private function seedData(int $employees, int $shiftsEach): void
    {
        $days = ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06', '2026-06-07'];
        $rowsEmp = [];
        for ($i = 0; $i < $employees; $i++) {
            $rowsEmp[] = ['company_id' => $this->company->id, 'first_name' => 'E'.$i, 'last_name' => 'Perf', 'status' => 'active', 'hire_date' => '2026-01-01', 'employee_number' => "P-{$i}", 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rowsEmp, 500) as $chunk) {
            DB::table('workforce_employees')->insert($chunk);
        }
        $empIds = DB::table('workforce_employees')->where('company_id', $this->company->id)->pluck('id');

        $rowsShift = [];
        foreach ($empIds as $eid) {
            for ($s = 0; $s < $shiftsEach; $s++) {
                $d = $days[$s % count($days)];
                $rowsShift[] = [
                    'company_id' => $this->company->id, 'employee_id' => $eid, 'date' => $d,
                    'start_at' => "{$d} 09:00:00", 'end_at' => "{$d} 17:00:00", 'timezone' => 'Europe/Paris',
                    'status' => $s % 2 ? 'published' : 'draft', 'template_snapshot' => json_encode(['break_duration_minutes' => 60]),
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rowsShift, 1000) as $chunk) {
            DB::table('workforce_shifts')->insert($chunk);
        }
    }

    private function measure(int $employees, int $shiftsEach): array
    {
        $this->seedData($employees, $shiftsEach);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);

        $board = PlanningBoardReadModel::board(
            $this->company->id,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-07'),
            null,
            [],
        );

        $ms = round((microtime(true) - $start) * 1000, 1);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[W-G4A perf] {$employees} collaborateurs × {$shiftsEach} shifts → ".count($board['shifts'])." shifts, {$ms} ms, {$queries} requêtes\n");

        return ['ms' => $ms, 'queries' => $queries, 'shifts' => count($board['shifts'])];
    }

    public function test_board_scales_without_n_plus_one_100(): void
    {
        $r = $this->measure(100, 5);
        $this->assertSame(500, $r['shifts']);
        // Eager-load → nombre de requêtes CONSTANT (pas proportionnel au nombre d'employés).
        $this->assertLessThan(15, $r['queries'], 'pas de N+1 (requêtes bornées)');
    }

    public function test_board_scales_without_n_plus_one_500(): void
    {
        $r = $this->measure(500, 5);
        $this->assertSame(2500, $r['shifts']);
        $this->assertLessThan(15, $r['queries'], 'pas de N+1 même à 500 collaborateurs');
    }
}
