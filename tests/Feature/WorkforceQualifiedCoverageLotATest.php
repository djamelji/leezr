<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermissionCatalog;
use App\Core\Models\Company;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\ScheduledActivity;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\ReadModels\CoverageReadModel;
use App\Modules\Workforce\UseCases\CreateScheduledActivityUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning Lot A (ADR-710) — couverture QUALIFIÉE par catégorie.
 * Vérifie : la formation ne gonfle pas la couverture Route ; les activités qualifiées priment sur les
 * sous-intervalles (formation matin + route après-midi) ; une plage sans catégorie ne compte pas en production.
 */
class WorkforceQualifiedCoverageLotATest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;
    private CoverageCategory $formation;

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
        $this->company = Company::create(['name' => 'LA', 'slug' => 'la-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#4b4bd6']);
        $this->formation = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'FORM', 'name' => 'Formation', 'color' => '#1f8a54']);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(): Employee
    {
        return Employee::create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
    }

    private function shift(Employee $e, string $start, string $end, ?int $catId): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => substr($start, 0, 10),
            'start_at' => $start, 'end_at' => $end, 'timezone' => 'Europe/Paris', 'status' => Shift::STATUS_PUBLISHED, 'published_at' => now(),
            'template_snapshot' => $catId ? ['coverage_category_id' => $catId] : [],
        ]);
    }

    private function reqRow(array $r, int $reqId): array
    {
        return collect($r['requirements'])->firstWhere('requirement_id', $reqId);
    }

    public function test_formation_does_not_inflate_route_coverage(): void
    {
        // 20 routes requises ; 16 shifts Route + 3 Formation + 2 sans catégorie, tous 09–17.
        $reqRoute = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 20, 'coverage_category_id' => $this->route->id, 'enabled' => true]);
        $reqForm = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 3, 'coverage_category_id' => $this->formation->id, 'enabled' => true]);

        for ($i = 0; $i < 16; $i++) {
            $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 17:00:00', $this->route->id);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 17:00:00', $this->formation->id);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 17:00:00', null);
        }

        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $route = $this->reqRow($r, $reqRoute->id);
        $form = $this->reqRow($r, $reqForm->id);

        $this->assertSame(16, $route['assigned'], 'Route : seuls les 16 shifts Route comptent (ni formation ni sans catégorie).');
        $this->assertSame('under', $route['status']);
        $this->assertSame(4, $route['gap']);
        $this->assertSame(3, $form['assigned'], 'Formation : 3/3.');
        $this->assertSame('covered', $form['status']);
    }

    public function test_qualified_activity_overrides_shift_category_per_interval(): void
    {
        // Besoin Route 13:00–17:00 = 1. Un salarié : shift Route 09–17 MAIS formation (activité) 09–12.
        $reqRoute = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '13:00', 'end_time' => '17:00', 'headcount' => 1, 'coverage_category_id' => $this->route->id, 'enabled' => true]);
        $reqFormAm = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '12:00', 'headcount' => 1, 'coverage_category_id' => $this->formation->id, 'enabled' => true]);

        $e = $this->employee();
        $shift = $this->shift($e, '2026-11-02 09:00:00', '2026-11-02 17:00:00', $this->route->id);
        // Activité Formation 09:00–12:00 → prime sur ce créneau.
        app(CreateScheduledActivityUseCase::class)->execute($this->company->id, $shift->id, [
            'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 12:00:00', 'coverage_category_id' => $this->formation->id, 'label' => 'Formation',
        ]);

        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');

        // Matin 09–12 : la formation compte, PAS la route.
        $this->assertSame(1, $this->reqRow($r, $reqFormAm->id)['assigned'], 'Formation 09–12 : couverte par l\'activité.');
        // Après-midi 13–17 : la route (catégorie du shift) compte.
        $this->assertSame(1, $this->reqRow($r, $reqRoute->id)['assigned'], 'Route 13–17 : couverte par la catégorie du shift.');
    }

    public function test_requirement_without_category_counts_all_presence_backward_compatible(): void
    {
        $req = CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => 1, 'enabled' => true]);
        $this->shift($this->employee(), '2026-11-02 09:00:00', '2026-11-02 17:00:00', null);

        $r = CoverageReadModel::forRange($this->company->id, '2026-11-02', '2026-11-02');
        $this->assertSame('covered', $this->reqRow($r, $req->id)['status'], 'Sans catégorie : toute présence compte (rétro-compatible).');
    }
}
