<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleCatalogReadModel;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-839 (Leezr 4.2 M3) — what is shown matches what is allowed :
 *  - a Platform tab is displayed only with the permission its API requires (contract between the page and the routes) ;
 *  - forbidden / module-off addresses get an explicit page, never a silent redirect (router contract) ;
 *  - least-privilege driver, manager without modules.manage, cross-tenant, creation-only operator : refused by the server ;
 *  - a module outside the plan is reported « locked_plan » with its target plan and is never activated implicitly.
 */
class Adr839PermissionsAndUnavailableFeaturesTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();
        PlatformPermissionCatalog::sync();
        FieldDefinitionCatalog::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Fictive Transport', 'slug' => 'fictive-transport', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        $this->setUpCompanyRbac($this->company);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    private function memberWith(array $permissions, bool $administrative = false, ?Company $company = null): User
    {
        $company ??= $this->company;
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => 'r_'.Str::random(6), 'name' => 'Role', 'is_administrative' => $administrative]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user = User::factory()->create();
        $company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $user;
    }

    private function operator(array $permissions): PlatformUser
    {
        $user = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'M3', 'email' => Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'r_'.Str::random(6), 'name' => 'Role '.Str::random(4)]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user->roles()->attach($role);

        return $user;
    }

    private function routePermission(string $uri): ?string
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('GET', $route->methods(), true)) {
                foreach ($route->gatherMiddleware() as $mw) {
                    if (is_string($mw) && str_starts_with($mw, 'platform.permission:')) {
                        return substr($mw, strlen('platform.permission:'));
                    }
                }
            }
        }

        return null;
    }

    // ── Platform : page ↔ API contract (UXP-03 / SEC-08) ─────────────────────────────────────────────────────

    public function test_platform_tabs_declare_the_permission_their_api_requires(): void
    {
        $contract = [
            'js/pages/platform/dashboard/[tab].vue' => ['activity' => 'api/platform/activity', 'funnel' => 'api/platform/onboarding/funnel'],
            'js/pages/platform/billing/[tab].vue' => ['coupons' => 'api/platform/billing/coupons', 'tariff-review' => 'api/platform/pricing/review-subscriptions'],
        ];
        foreach ($contract as $file => $tabs) {
            $source = file_get_contents(resource_path($file));
            foreach ($tabs as $tab => $uri) {
                $permission = $this->routePermission($uri);
                $this->assertNotNull($permission, "$uri is permission-guarded");
                $this->assertMatchesRegularExpression("/tab: '".preg_quote($tab, '/')."', permission: '".preg_quote($permission, '/')."'/", $source,
                    "$file : the « $tab » tab declares $permission (= $uri)");
            }
            $this->assertStringContainsString('platformAuth.hasPermission(item.permission)', $source, "$file filters its tabs");
            $this->assertStringContainsString('platform-tab-forbidden', $source, "$file explains a tab reached by its address");
        }
    }

    public function test_router_never_redirects_silently(): void
    {
        $guards = file_get_contents(resource_path('js/plugins/1.router/guards.js'));
        $this->assertStringContainsString("name: 'company-module-unavailable'", $guards, 'module off → explicit page');
        $this->assertStringNotContainsString("toast('Module not available for your company.'", $guards);
        $this->assertSame(2, substr_count($guards, "name: 'platform403'"), 'Platform permission + module-off → explicit page');
        $this->assertStringNotContainsString("return to.path === '/platform' ? false : '/platform'", $guards);
        $this->assertStringNotContainsString('setInterval', file_get_contents(resource_path('js/pages/company/403.vue')), 'no automatic bounce');
        $this->assertFileExists(resource_path('js/pages/platform/403.vue'));
    }

    public function test_finance_profile_is_refused_the_apis_of_hidden_tabs(): void
    {
        $finance = $this->operator(['view_billing', 'view_dashboard']);
        foreach (['/api/platform/billing/coupons', '/api/platform/pricing/review-subscriptions', '/api/platform/activity', '/api/platform/onboarding/funnel'] as $uri) {
            $this->actingAs($finance, 'platform')->getJson($uri)->assertForbidden();
        }
        $this->actingAs($finance, 'platform')->getJson('/api/platform/billing/scheduled-debits')->assertOk();
    }

    public function test_creation_only_operator_cannot_read_or_change_other_companies(): void
    {
        $creator = $this->operator(['create_companies']);
        $this->actingAs($creator, 'platform')->getJson('/api/platform/companies')->assertForbidden();
        $this->actingAs($creator, 'platform')->getJson("/api/platform/companies/{$this->company->id}")->assertForbidden();
        $this->actingAs($creator, 'platform')->putJson("/api/platform/companies/{$this->company->id}/plan", ['plan_key' => 'starter'])->assertForbidden();
    }

    // ── Company : situations A, B, C ─────────────────────────────────────────────────────────────────────────

    public function test_least_privilege_driver_cannot_read_the_directory_nor_another_company(): void
    {
        $driver = $this->memberWith(['theme.view', 'theme.manage', 'shipments.view_own', 'shipments.manage_status']);
        $this->actingAs($driver)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/company/members')->assertForbidden();

        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        $response = $this->actingAs($driver)->withHeaders(['X-Company-Id' => $other->id])->getJson('/api/company/members');
        $this->assertContains($response->status(), [403, 404], 'another company is refused');
        $this->assertStringNotContainsString('@', (string) $response->getContent());

        $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/company/members')->assertOk();
    }

    public function test_manager_without_modules_manage_cannot_activate_a_module(): void
    {
        $manager = $this->memberWith(['members.view', 'settings.view'], true);
        DB::table('company_modules')->where('company_id', $this->company->id)->where('module_key', 'workforce_planning')->update(['is_enabled_for_company' => false]);
        $this->actingAs($manager)->withHeaders(['X-Company-Id' => $this->company->id])->putJson('/api/modules/workforce_planning/enable')->assertForbidden();
    }

    public function test_a_module_outside_the_plan_is_locked_with_its_target_plan_and_never_activated_implicitly(): void
    {
        DB::table('platform_modules')->where('key', 'workforce_payroll')->update(['min_plan_override' => 'business']);
        DB::table('company_modules')->where('company_id', $this->company->id)->where('module_key', 'workforce_payroll')->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $entry = collect(ModuleCatalogReadModel::forCompany($this->company->fresh()))->firstWhere('key', 'workforce_payroll');
        $this->assertSame('locked_plan', $entry['display_state']);
        $this->assertSame('business', $entry['upgrade_target_plan']);
        $this->assertFalse($entry['is_active']);

        $response = $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])->putJson('/api/modules/workforce_payroll/enable');
        $this->assertGreaterThanOrEqual(400, $response->status(), 'a premium module is never activated outside the billing engine');
        $entry = collect(ModuleCatalogReadModel::forCompany($this->company->fresh()))->firstWhere('key', 'workforce_payroll');
        $this->assertFalse($entry['is_active']);
    }
}
