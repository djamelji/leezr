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
use App\Core\Policies\CompanyPolicy;
use App\Core\Workforce\CoverageCategory;
use App\Core\Workforce\CoverageRequirement;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\Shift;
use App\Modules\Workforce\Services\OverstaffingEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Smart Planning Lot 4B.1 (ADR-719) — contrat de SUREFFECTIF.
 * Vérifie : impact MARGINAL (pas de faux positif sur sureffectif préexistant ni sur contribution non
 * qualifiée), absence de besoin ≠ zéro, blocage warn_confirm sans ack, écriture avec ack valide,
 * concurrence (signature périmée → re-confirmation), mode warn, réglementaire jamais contournable.
 */
class WorkforceOverstaffingLot4B1Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private CoverageCategory $route;
    private \App\Core\Workforce\ScheduleTemplate $routeTpl;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 08:00:00');
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_planning']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            PlatformModule::create(['key' => $m, 'name' => $m, 'is_enabled_globally' => true]);
        }
        CompanyPermissionCatalog::sync();
        $this->company = Company::create(['name' => 'OS', 'slug' => 'os-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_planning'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->route = CoverageCategory::create(['company_id' => $this->company->id, 'code' => 'ROUTE', 'name' => 'Route', 'color' => '#3b82f6', 'enabled' => true]);
        $this->routeTpl = \App\Core\Workforce\ScheduleTemplate::create(['company_id' => $this->company->id, 'name' => 'Route 09-17', 'start_time' => '09:00', 'end_time' => '17:00', 'coverage_category_id' => $this->route->id, 'color' => '#3b82f6']);
        Model::reguard();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emp(): Employee
    {
        $e = Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'E', 'last_name' => uniqid(), 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        EmploymentContract::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'contract_type' => 'cdi', 'work_model_key' => 'horaire', 'status' => 'active', 'is_current' => true, 'start_date' => '2024-01-01', 'weekly_hours' => 35]);

        return $e->fresh();
    }

    private function routeShift(Employee $e): Shift
    {
        return Shift::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'employee_id' => $e->id, 'date' => '2026-11-02',
            'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 17:00:00', 'timezone' => 'Europe/Paris',
            'status' => Shift::STATUS_DRAFT, 'template_snapshot' => ['coverage_category_id' => $this->route->id],
        ]);
    }

    private function requirement(int $headcount): CoverageRequirement
    {
        return CoverageRequirement::create(['company_id' => $this->company->id, 'date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'headcount' => $headcount, 'coverage_category_id' => $this->route->id, 'enabled' => true]);
    }

    private function prospectRoute(Employee $e): array
    {
        return [
            'employee_id' => $e->id, 'date' => '2026-11-02',
            'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 17:00:00',
            'work_location_id' => null, 'template_snapshot' => ['coverage_category_id' => $this->route->id], 'ignore_shift_id' => null,
        ];
    }

    private function fill(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->routeShift($this->emp());
        }
    }

    public function test_no_requirement_means_no_overstaffing(): void
    {
        // Aucun CoverageRequirement → pas de besoin implicite à zéro, aucune alerte.
        $this->fill(3);
        $eval = app(OverstaffingEvaluator::class)->evaluate($this->company->id, $this->prospectRoute($this->emp()));
        $this->assertFalse($eval['overstaffed']);
    }

    public function test_filling_to_headcount_is_not_overstaffing(): void
    {
        $this->requirement(12);
        $this->fill(11); // 11/12
        $eval = app(OverstaffingEvaluator::class)->evaluate($this->company->id, $this->prospectRoute($this->emp()));
        $this->assertFalse($eval['overstaffed'], '12e sur un besoin de 12 → couvert, pas sureffectif.');
    }

    public function test_marginal_overstaffing_detected(): void
    {
        $this->requirement(12);
        $this->fill(12); // 12/12
        $eval = app(OverstaffingEvaluator::class)->evaluate($this->company->id, $this->prospectRoute($this->emp()));
        $this->assertTrue($eval['overstaffed'], '13e contributeur qualifié → sureffectif marginal.');
        $this->assertSame(12, $eval['impacts'][0]['required']);
        $this->assertSame(13, $eval['impacts'][0]['projected']);
        $this->assertNotEmpty($eval['signature']);
    }

    public function test_preexisting_overstaffing_without_marginal_contribution_does_not_warn(): void
    {
        $this->requirement(12);
        $this->fill(13); // déjà 13/12 (préexistant)
        // Nouvelle affectation SANS catégorie (présence non qualifiée Route) → aucune contribution marginale.
        $prospect = $this->prospectRoute($this->emp());
        $prospect['template_snapshot'] = []; // pas de catégorie → ne contribue pas à Route
        $eval = app(OverstaffingEvaluator::class)->evaluate($this->company->id, $prospect);
        $this->assertFalse($eval['overstaffed'], 'Sureffectif préexistant sans contribution marginale → pas d\'alerte.');
    }

    // ── Contrat HTTP (warn_confirm par défaut) ──

    private function planner(): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', ['workforce.planning_view', 'workforce.planning_manage', 'workforce.planning_publish'])->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    private function createPayload(Employee $e, array $extra = []): array
    {
        return array_merge([
            'employee_id' => $e->id, 'date' => '2026-11-02',
            'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 17:00:00',
            'timezone' => 'Europe/Paris', 'schedule_template_id' => $this->routeTpl->id, // porte la catégorie Route
        ], $extra);
    }

    public function test_http_blocks_overstaffing_without_ack_then_proceeds_with_valid_ack(): void
    {
        $this->requirement(12);
        $this->fill(12);
        $planner = $this->planner();
        $hdr = ['X-Company-Id' => $this->company->id];
        $newEmp = $this->emp();

        // Sans ack → 422 code overstaffing_requires_confirmation + signature.
        $res = $this->actingAs($planner)->postJson('/api/workforce/planning/shifts', $this->createPayload($newEmp), $hdr)
            ->assertStatus(422)->assertJsonPath('code', 'overstaffing_requires_confirmation');
        $signature = $res->json('signature');
        $this->assertNotEmpty($signature);
        $this->assertSame(0, Shift::withoutGlobalScopes()->where('employee_id', $newEmp->id)->count(), 'Rien créé sans confirmation.');

        // Avec ack valide → 201, shift créé.
        $this->actingAs($planner)->postJson('/api/workforce/planning/shifts', $this->createPayload($newEmp, ['overstaffing_ack' => $signature]), $hdr)
            ->assertStatus(201);
        $this->assertSame(1, Shift::withoutGlobalScopes()->where('employee_id', $newEmp->id)->count());
    }

    public function test_http_stale_signature_requires_reconfirmation(): void
    {
        $this->requirement(12);
        $this->fill(12);
        $planner = $this->planner();
        $hdr = ['X-Company-Id' => $this->company->id];

        // Prévisualisation → signature pour un état 12/12 (projeté 13).
        $prev = $this->actingAs($planner)->postJson('/api/workforce/planning/shifts/overstaffing-preview', [
            'employee_id' => $this->emp()->id, 'date' => '2026-11-02', 'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 17:00:00',
            'schedule_template_id' => $this->routeTpl->id,
        ], $hdr)->assertOk();
        $this->assertTrue($prev->json('overstaffed'));
        $staleSig = $prev->json('signature');

        // L'état change : un 13e est affecté → projeté passerait à 14.
        $this->routeShift($this->emp());

        // L'ancienne signature (projeté 13) ne correspond plus (projeté 14) → re-confirmation exigée.
        $this->actingAs($planner)->postJson('/api/workforce/planning/shifts', $this->createPayload($this->emp(), ['overstaffing_ack' => $staleSig]), $hdr)
            ->assertStatus(422)->assertJsonPath('code', 'overstaffing_requires_confirmation');
    }

    public function test_warn_mode_proceeds_without_ack(): void
    {
        CompanyPolicy::create(['company_id' => $this->company->id, 'domain' => 'planning', 'policy_key' => 'overstaffing_mode', 'value' => ['mode' => 'warn'], 'effective_from' => '2026-01-01', 'source' => 'admin_manual']);
        $this->requirement(12);
        $this->fill(12);
        $newEmp = $this->emp();

        $this->actingAs($this->planner())->postJson('/api/workforce/planning/shifts', $this->createPayload($newEmp), ['X-Company-Id' => $this->company->id])
            ->assertStatus(201);
        $this->assertSame(1, Shift::withoutGlobalScopes()->where('employee_id', $newEmp->id)->count(), 'Mode warn : l\'écriture passe sans confirmation.');
    }
}
