<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-816 (L0) — Company data-leak fixes proven by ADR-815 / ADR-815.2, per profile :
 *  - a PERSONAL right (`timesheet_view` « own », `leave_request`) never yields company-wide aggregates ;
 *    collective widgets follow the existing « see all » authority (WorkforceAccess) ;
 *  - the member directory (`members.view`, granted to field roles) never exposes account details
 *    (login e-mail, account state, role, owner/admin flags) — only access administrators see them ;
 *  - module settings, quotes and deactivation impact are administrative reads (`modules.manage`) ;
 *  - tenant isolation holds for every endpoint touched.
 */
class Adr816CompanySecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    /** @var array<string, User> */
    private array $u = [];

    private const COLLECTIVE = ['workforce.clock_live', 'workforce.anomalies', 'workforce.hours_trend', 'workforce.leave_pending', 'workforce.headcount', 'planning.shifts'];

    private const PROFILES = [
        // key => [is_administrative, permissions]
        'admin' => [true, ['members.view', 'members.invite', 'members.manage', 'members.credentials', 'modules.manage', 'settings.view']],
        'rh' => [true, ['workforce.view', 'workforce.manage', 'workforce.leave_view_all', 'workforce.leave_approve', 'workforce.sensitive_read']],
        'manager' => [true, ['workforce.view', 'workforce.timesheet_view', 'workforce.timesheet_approve', 'workforce.leave_approve', 'workforce.leave_view_all', 'workforce.planning_view', 'workforce.planning_publish']],
        'ops' => [true, ['workforce.view', 'workforce.planning_view', 'workforce.planning_manage', 'workforce.timesheet_view']],
        'salarie' => [false, ['workforce.leave_request', 'workforce.timesheet_view', 'workforce.timesheet_submit']],
        'driver' => [false, ['settings.view', 'members.view', 'shipments.view_own', 'shipments.manage_status']],
        'limited' => [false, ['settings.view']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        $this->seed(\Database\Seeders\ModuleProductConfigSeeder::class);
        CompanyPermissionCatalog::sync();
        \App\Core\Jobdomains\JobdomainRegistry::sync();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        $this->company = $this->makeCompany('Sécurité Co');
        $this->other = $this->makeCompany('Autre Co');

        $this->u['owner'] = User::factory()->create(['email' => 'owner@sec.test']);
        $this->company->memberships()->create(['user_id' => $this->u['owner']->id, 'role' => 'owner']);
        foreach (self::PROFILES as $key => [$admin, $perms]) {
            $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => $key, 'name' => ucfirst($key), 'is_administrative' => $admin]);
            $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id'));
            $this->u[$key] = User::factory()->create(['email' => "{$key}@sec.test"]);
            $this->company->memberships()->create(['user_id' => $this->u[$key]->id, 'role' => 'user', 'company_role_id' => $role->id]);
        }
        $this->u['outsider'] = User::factory()->create(['email' => 'outsider@sec.test']);
        $this->other->memberships()->create(['user_id' => $this->u['outsider']->id, 'role' => 'owner']);
    }

    private function makeCompany(string $name): Company
    {
        $c = Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        $c->jobdomains()->attach(\App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->value('id'));
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(['company_id' => $c->id, 'module_key' => $key], ['is_enabled_for_company' => true]);
            if ($def->type !== 'core') {
                \App\Core\Modules\CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => $key, 'reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT]);
            }
        }

        return $c;
    }

    private function as(string $who, ?Company $company = null)
    {
        return $this->actingAs($this->u[$who])->withHeaders(['X-Company-Id' => ($company ?? $this->company)->id]);
    }

    /** @return array<string> keys resolved without error for this profile */
    private function resolvedWidgets(string $who): array
    {
        $res = $this->as($who)->postJson('/api/dashboard/widgets/data', ['widgets' => array_map(fn ($k) => ['key' => $k], self::COLLECTIVE)])->assertOk()->json('results');

        return array_values(array_map(fn ($r) => $r['key'], array_filter($res, fn ($r) => ! isset($r['error']))));
    }

    private function catalogKeys(string $who): array
    {
        return collect($this->as($who)->getJson('/api/dashboard/widgets/catalog')->assertOk()->json('widgets'))->pluck('key')->all();
    }

    // ── A. Widgets ─────────────────────────────────────────────────────────

    public function test_personal_rights_never_yield_company_wide_aggregates(): void
    {
        foreach (['salarie', 'driver', 'limited'] as $who) {
            $this->assertSame([], $this->resolvedWidgets($who), "{$who} must not read collective widgets");
            $this->assertSame([], array_values(array_intersect(self::COLLECTIVE, $this->catalogKeys($who))), "{$who} catalog");
        }
    }

    public function test_collective_widgets_stay_available_to_profiles_that_see_everyone(): void
    {
        $this->assertEqualsCanonicalizing(self::COLLECTIVE, $this->resolvedWidgets('owner'));
        // manager : time + leave + planning « see all » (timesheet_approve, leave_approve, planning_publish) ; not every employee (no workforce.manage)
        $this->assertEqualsCanonicalizing(['workforce.clock_live', 'workforce.anomalies', 'workforce.hours_trend', 'workforce.leave_pending', 'planning.shifts'], $this->resolvedWidgets('manager'));
        // RH with workforce.view + manage : every employee + leave ; no time authority ; no planning_view → no shifts
        $this->assertEqualsCanonicalizing(['workforce.headcount', 'workforce.leave_pending'], $this->resolvedWidgets('rh'));
        // operations manager (team scope only) : no company-wide aggregate
        $this->assertSame([], $this->resolvedWidgets('ops'));
    }

    public function test_a_saved_layout_never_shows_a_widget_the_user_may_not_read(): void
    {
        $tiles = [['key' => 'workforce.clock_live', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4, 'scope' => 'company'],
            ['key' => 'workforce.leave_pending', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 4, 'scope' => 'company']];
        $row = \App\Modules\Dashboard\CompanyDashboardLayout::create(['company_id' => $this->company->id, 'user_id' => $this->u['rh']->id, 'layout_json' => $tiles]);

        // RH sees leave (leave_approve) but not presence (no time authority) ; the stored layout is untouched.
        $res = $this->as('rh')->getJson('/api/dashboard/layout')->assertOk();
        $this->assertSame(['workforce.leave_pending'], array_column($res->json('layout'), 'key'));
        $this->assertTrue($res->json('stored'), 'the client must know a layout is stored (never auto-replace it)');
        $this->assertFalse($this->as('salarie')->getJson('/api/dashboard/layout')->assertOk()->json('stored'));
        $this->assertCount(2, $row->fresh()->layout_json);
    }

    // ── B. Directory ───────────────────────────────────────────────────────

    public function test_directory_viewers_never_see_account_details(): void
    {
        $members = $this->as('driver')->getJson('/api/company/members')->assertOk()->json('members');
        $this->assertNotEmpty($members);
        foreach ($members as $m) {
            $this->assertArrayNotHasKey('email', $m['user'], 'driver must not read login e-mails');
            $this->assertArrayNotHasKey('profile_completeness', $m);
        }

        $people = $this->as('driver')->getJson('/api/company/people')->assertOk()->json('data');
        $this->assertNotEmpty($people);
        foreach ($people as $p) {
            $this->assertNull($p['email'], 'people list e-mail');
            $this->assertNull($p['account_state'], 'people list account state');
            $this->assertNull($p['role'], 'people list role');
            $this->assertEmpty($p['is_owner'] ?? null, 'people list owner flag');
        }

        $file = $this->as('driver')->getJson('/api/company/people/user-'.$this->u['admin']->id)->assertOk()->json();
        $this->assertNull($file['identity']['login_email']);
        $this->assertNull($file['account']['role']);
        $this->assertNull($file['account']['is_admin']);
        $this->assertNull($file['account']['is_owner']);
        $this->assertNull($file['membership_id']);
        $this->assertFalse($file['can']['view_access']);
        $this->assertSame(trim($this->u['admin']->first_name.' '.$this->u['admin']->last_name), $file['identity']['display_name']);
    }

    public function test_access_administrators_keep_the_full_directory(): void
    {
        $members = $this->as('admin')->getJson('/api/company/members')->assertOk()->json('members');
        $this->assertContains('driver@sec.test', array_column(array_column($members, 'user'), 'email'));

        $people = collect($this->as('admin')->getJson('/api/company/people')->assertOk()->json('data'));
        $this->assertNotNull($people->firstWhere('email', 'driver@sec.test'));
        $this->assertNotNull($people->firstWhere('email', 'driver@sec.test')['role']);

        $file = $this->as('admin')->getJson('/api/company/people/user-'.$this->u['driver']->id)->assertOk()->json();
        $this->assertSame('driver@sec.test', $file['identity']['login_email']);
        $this->assertTrue($file['can']['view_access']);
        $this->assertNotNull($file['account']['role']);
    }

    // ── C. Modules ─────────────────────────────────────────────────────────

    public function test_module_settings_quotes_and_deactivation_impact_are_administrative(): void
    {
        $reads = ['/api/modules/workforce/settings', '/api/modules/quote?keys[]=workforce_payroll', '/api/modules/workforce/deactivation-preview'];
        foreach (['salarie', 'driver', 'limited', 'manager', 'rh'] as $who) {
            foreach ($reads as $url) {
                $this->as($who)->getJson($url)->assertForbidden();
            }
            // The public catalogue (active modules) stays readable : every screen needs it.
            $this->as($who)->getJson('/api/modules')->assertOk();
        }
        foreach (['owner', 'admin'] as $who) {
            foreach ($reads as $url) {
                $this->as($who)->getJson($url)->assertOk();
            }
        }
    }

    // ── Tenant isolation ───────────────────────────────────────────────────

    public function test_no_endpoint_touched_by_l0_crosses_companies(): void
    {
        foreach (['/api/dashboard/widgets/catalog', '/api/company/members', '/api/company/people', '/api/modules/workforce/settings', '/api/modules/quote?keys[]=workforce'] as $url) {
            $this->as('outsider', $this->company)->getJson($url)->assertForbidden();
        }
        $this->as('outsider', $this->company)->postJson('/api/dashboard/widgets/data', ['widgets' => [['key' => 'workforce.headcount']]])->assertForbidden();
        $this->as('owner', $this->company)->getJson('/api/company/people/user-'.$this->u['outsider']->id)->assertNotFound();
    }
}
