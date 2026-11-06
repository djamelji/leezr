<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\PlatformModule;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\ScheduleTemplate;
use App\Core\Workforce\WeekTemplate;
use App\Core\Workforce\WeekTemplateLine;
use App\Modules\Workforce\Services\ScheduleTemplateCompositionValidator;
use App\Modules\Workforce\UseCases\CreateScheduleTemplateUseCase;
use App\Modules\Workforce\UseCases\RepeatWeekTemplateUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BENCHMARK M4 (ADR-727 §charge) — mesure preview/commit de répétition 8 semaines à 5/25/50 collaborateurs.
 * Environnement : SQLite in-memory (CI) — les timings absolus sont optimistes vs MySQL ; le NOMBRE de
 * requêtes, de Shifts/ScheduledActivities et la mémoire sont représentatifs (DB-agnostiques).
 *
 * @group benchmark
 */
class WorkforceRepeatLoadM4Bench extends TestCase
{
    use RefreshDatabase;

    public function test_load_profile(): void
    {
        // Benchmark manuel (lourd). Pour mesurer : remplacer par `$this->measure();` et lancer ce fichier.
        // Métriques capturées (SQLite in-memory, répétition 8 semaines) consignées dans l'ADR-727 §charge.
        $this->markTestSkipped('Benchmark manuel — voir ADR-727 §charge pour les métriques mesurées.');
    }

    public function measure(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $company = Company::create(['name' => 'BENCH', 'slug' => 'bench-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $route = CoverageCategory::create(['company_id' => $company->id, 'code' => 'ROUTE', 'name' => 'Route', 'enabled' => true]);
        $comp = app(ScheduleTemplateCompositionValidator::class)->validateAndNormalize([
            'schema_version' => 1,
            'activities' => [['label' => 'Brief', 'coverage_category_id' => $route->id, 'offset_minutes' => 0, 'duration_minutes' => 30], ['label' => 'Tournée', 'coverage_category_id' => $route->id, 'offset_minutes' => 60, 'duration_minutes' => 120]],
            'breaks' => [['offset_minutes' => 210, 'duration_minutes' => 30]],
        ], '09:00', '17:00');
        $tpl = app(CreateScheduleTemplateUseCase::class)->execute(companyId: $company->id, name: 'Bench', startTime: '09:00', endTime: '17:00', coverageCategoryId: $route->id, composition: $comp);
        Model::reguard();

        fwrite(STDERR, "\n=== BENCH M4 — répétition 8 semaines (SQLite in-memory) ===\n");
        foreach ([5, 25, 50] as $n) {
            // n salariés, semaine nominative 5 jours/salarié → n*5 lignes × 8 semaines.
            $emps = [];
            for ($i = 0; $i < $n; $i++) {
                $e = Employee::withoutGlobalScopes()->create(['company_id' => $company->id, 'first_name' => 'E'.$i, 'last_name' => 'B'.$i, 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
                EmploymentContract::create(['company_id' => $company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);
                $emps[] = $e->id;
            }
            $wt = WeekTemplate::create(['company_id' => $company->id, 'name' => 'Bench '.$n, 'strategy' => 'nominative', 'scope' => 'company', 'enabled' => true]);
            $o = 0;
            foreach ($emps as $eid) {
                for ($d = 0; $d < 5; $d++) {
                    WeekTemplateLine::create(['company_id' => $company->id, 'week_template_id' => $wt->id, 'day_index' => $d, 'schedule_template_id' => $tpl->id, 'kind' => 'nominative', 'employee_id' => $eid, 'sort_order' => $o++]);
                }
            }
            $r = app(RepeatWeekTemplateUseCase::class);

            // Preview
            DB::flushQueryLog();
            DB::enableQueryLog();
            $t0 = microtime(true);
            $prev = $r->preview($company->id, $wt->id, '2026-11-18', '8', [], null, 1);
            $pvMs = round((microtime(true) - $t0) * 1000);
            $pvQ = count(DB::getQueryLog());
            DB::disableQueryLog();

            // Commit
            DB::flushQueryLog();
            DB::enableQueryLog();
            $t0 = microtime(true);
            $res = $r->commit($company->id, $wt->id, '2026-11-18', '8', [], null, null, null, 1);
            $cmMs = round((microtime(true) - $t0) * 1000);
            $cmQ = count(DB::getQueryLog());
            DB::disableQueryLog();

            $acts = \App\Core\Workforce\ScheduledActivity::withoutGlobalScopes()->whereIn('shift_id', $res['created'])->count();
            $mem = round(memory_get_peak_usage(true) / 1048576);
            fwrite(STDERR, sprintf(
                "n=%-3d | shifts=%-4d acts=%-4d | preview %5dms %6dq | commit %6dms %7dq | peakMem %dMB\n",
                $n, $res['created_count'], $acts, $pvMs, $pvQ, $cmMs, $cmQ, $mem
            ));

            // Nettoyage pour l'itération suivante.
            \App\Core\Workforce\ScheduledActivity::withoutGlobalScopes()->whereIn('shift_id', $res['created'])->delete();
            \App\Core\Workforce\Shift::withoutGlobalScopes()->whereIn('id', $res['created'])->delete();
            WeekTemplateLine::withoutGlobalScopes()->where('week_template_id', $wt->id)->delete();
            $wt->delete();
            Employee::withoutGlobalScopes()->whereIn('id', $emps)->delete();
        }
        fwrite(STDERR, "=== fin bench ===\n");
        Carbon::setTestNow();
        $this->assertTrue(true);
    }
}
