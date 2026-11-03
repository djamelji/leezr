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
use App\Core\Workforce\PayrollRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRODUCT-UX-3 / P2-A (ADR-682) — accueil orienté actions.
 * Garanties : RBAC, aucune donnée salariale, fix de la fuite paie du dashboard statistics,
 * scope label, et comptes de priorités réels.
 */
class CompanyHomePrioritiesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => ['workforce', 'workforce_payroll']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'W', 'is_enabled_globally' => true]);
        PlatformModule::create(['key' => 'workforce_payroll', 'name' => 'P', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->company = Company::create(['name' => 'Home Co', 'slug' => 'home-co', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        foreach (['workforce', 'workforce_payroll'] as $m) {
            CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => $m, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        \App\Core\Modules\ModuleRegistry::clearCache();

        // Active employee WITHOUT contract → onboarding_incomplete = 1.
        Employee::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'first_name' => 'Sol', 'last_name' => 'Oh', 'employee_number' => 'E1', 'hire_date' => '2024-01-01', 'status' => Employee::STATUS_ACTIVE]);

        // Draft payroll run with gross → payroll_to_control = 1, and a gross to (not) leak.
        PayrollRun::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'currency' => 'EUR', 'status' => PayrollRun::STATUS_DRAFT, 'employee_count' => 1, 'total_gross_cents' => 654321]);

        Model::reguard();
    }

    private function userWith(array $perms): User
    {
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        Membership::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'role' => 'owner']);

        return $u;
    }

    private function apiGet(User $u, string $path)
    {
        return $this->actingAs($u)->getJson('/api'.$path, ['X-Company-Id' => $this->company->id]);
    }

    public function test_priorities_require_workforce_view(): void
    {
        $this->apiGet($this->userWith(['workforce.leave_request']), '/workforce/dashboard/priorities')->assertForbidden();
    }

    public function test_priorities_expose_counts_without_any_salary(): void
    {
        $res = $this->apiGet($this->owner(), '/workforce/dashboard/priorities');
        $res->assertOk();
        // Onboarding + payroll priorities present with real counts.
        $res->assertJsonFragment(['key' => 'onboarding_incomplete']);
        $res->assertJsonFragment(['key' => 'payroll_to_control']);
        // NO salary field or amount anywhere.
        $body = $res->getContent();
        foreach (['_cents', 'total_gross', '654321', 'base_salary', 'net_payable'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "home priorities must not expose {$leak}");
        }
    }

    public function test_scope_label_is_team_for_non_view_all(): void
    {
        // workforce.view WITHOUT workforce.manage → hasViewAll('employee') = false → team.
        $res = $this->apiGet($this->userWith(['workforce.view']), '/workforce/dashboard/priorities');
        $res->assertOk()->assertJsonFragment(['scope' => 'team']);
        // Owner → global scope.
        $this->apiGet($this->owner(), '/workforce/dashboard/priorities')->assertJsonFragment(['scope' => 'all']);
    }

    public function test_dashboard_statistics_no_longer_leaks_payroll_gross_without_comp(): void
    {
        // Management user WITHOUT compensation_read must not receive payroll gross amounts.
        $noComp = $this->userWith(['workforce.view', 'workforce.payroll_prepare']);
        $res = $this->apiGet($noComp, '/workforce/dashboard/statistics');
        $res->assertOk();
        $this->assertStringNotContainsString('total_gross_cents', $res->getContent());
        $this->assertStringNotContainsString('654321', $res->getContent());
        // But the draft count is preserved (badge).
        $res->assertJsonPath('badges.draft_payrolls', 1);

        // Owner sees the gross.
        $this->apiGet($this->owner(), '/workforce/dashboard/statistics')->assertOk()->assertSee('total_gross_cents');
    }
}
