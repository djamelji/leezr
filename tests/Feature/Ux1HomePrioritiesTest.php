<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Home\HomeContext;
use App\Core\Home\HomePriorityProvider;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Core\Documents\Home\DocumentsHomePriorities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-776 (Company Experience 2.0 / UX1) — « À traiter » on the company home.
 *
 * Contributions come from the ACTIVE modules, are filtered by the viewer's REAL rights, are scoped
 * to the company, open a treatment, and a failing contribution never breaks the home.
 */
class Ux1HomePrioritiesTest extends TestCase
{
    use RefreshDatabase;

    private const MODULES = ['workforce', 'workforce_leave', 'logistics_shipments'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function company(array $modules = self::MODULES): Company
    {
        Jobdomain::updateOrCreate(['key' => 'logistique'], ['label' => 'Logistique', 'default_modules' => self::MODULES]);
        $company = Company::create(['name' => 'UX1 '.uniqid(), 'slug' => 'ux1-'.uniqid(), 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        foreach (self::MODULES as $key) {
            if (in_array($key, $modules, true)) {
                CompanyModuleActivationReason::create(['company_id' => $company->id, 'module_key' => $key, 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
            }
            CompanyModule::create(['company_id' => $company->id, 'module_key' => $key, 'is_enabled_for_company' => in_array($key, $modules, true)]);
        }
        ModuleRegistry::clearCache();

        return $company;
    }

    private function owner(Company $company): User
    {
        $user = User::factory()->create();
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'owner']);

        return $user;
    }

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => true]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $permissions)->pluck('id')->all());
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $user;
    }

    private function shipments(Company $company, User $creator): void
    {
        $base = ['company_id' => $company->id, 'created_by_user_id' => $creator->id, 'origin_address' => 'A', 'destination_address' => 'B'];
        Shipment::create($base + ['reference' => 'L-'.uniqid(), 'status' => 'in_transit', 'scheduled_at' => now()->subDay(), 'assigned_to_user_id' => $creator->id]);
        Shipment::create($base + ['reference' => 'U-'.uniqid(), 'status' => 'planned', 'scheduled_at' => now()->addDay()]);
        Shipment::create($base + ['reference' => 'U-'.uniqid(), 'status' => 'planned', 'scheduled_at' => now()->addDay()]);
    }

    private function priorities(User $user, Company $company): array
    {
        return $this->actingAs($user)->getJson('/api/dashboard/priorities', ['X-Company-Id' => $company->id])->assertOk()->json();
    }

    public function test_priorities_are_real_actionable_and_ordered_by_urgency(): void
    {
        $company = $this->company();
        $owner = $this->owner($company);
        $this->shipments($company, $owner);

        $res = $this->priorities($owner, $company);
        $byKey = collect($res['priorities'])->keyBy('key');

        $this->assertSame([], $res['unavailable']);
        $this->assertSame(1, $byKey['shipments_late']['count']);
        $this->assertSame(2, $byKey['shipments_unassigned']['count']);
        $this->assertSame('shipments_late', $res['priorities'][0]['key'], 'errors first');
        foreach ($res['priorities'] as $p) {
            $this->assertGreaterThan(0, $p['count'], 'nothing shown when there is nothing to do');
            $this->assertNotEmpty($p['to']['name'], 'opens a treatment');
            $this->assertNotSame('dashboard', $p['to']['name']);
            $this->assertEqualsCanonicalizing(['key', 'count', 'severity', 'icon', 'to', 'space'], array_keys($p));
        }
        $this->assertSame(['status' => 'in_transit'], $byKey['shipments_late']['to']['query'], 'opens the filtered list, not the module home');
    }

    public function test_only_what_the_viewer_may_see_and_act_on(): void
    {
        $company = $this->company();
        $owner = $this->owner($company);
        $this->shipments($company, $owner);
        $keys = fn (array $perms) => array_column($this->priorities($this->member($company, $perms), $company)['priorities'], 'key');

        $this->assertSame(['shipments_late'], $keys(['shipments.view']), 'cannot assign → not asked to assign');
        $this->assertEqualsCanonicalizing(['shipments_late', 'shipments_unassigned'], $keys(['shipments.view', 'shipments.assign']));
        $this->assertSame([], $keys(['shipments.assign']), 'assign without view opens nothing');
        $this->assertSame([], $keys([]));
        // Workforce items keep the gate of their own endpoint (workforce.view) — nothing widened.
        $this->assertSame([], $keys(['workforce.leave_approve']));
    }

    public function test_inactive_module_contributes_nothing_and_companies_never_mix(): void
    {
        $a = $this->company();
        $ownerA = $this->owner($a);
        $this->shipments($a, $ownerA);

        // Same data shape in a company where the business module is NOT active : nothing.
        $bare = $this->company(['workforce']);
        $ownerBare = $this->owner($bare);
        $this->shipments($bare, $ownerBare);
        $this->assertSame([], array_values(array_filter(array_column($this->priorities($ownerBare, $bare)['priorities'], 'key'), fn ($k) => str_starts_with($k, 'shipments_'))));

        // Another company with the module : sees ITS own counts only.
        $b = $this->company();
        $ownerB = $this->owner($b);
        $this->assertSame([], array_column($this->priorities($ownerB, $b)['priorities'], 'key'));
        $this->assertSame(1, collect($this->priorities($ownerA, $a)['priorities'])->firstWhere('key', 'shipments_late')['count']);

        // A member of A cannot read B's home.
        $this->actingAs($ownerA)->getJson('/api/dashboard/priorities', ['X-Company-Id' => $b->id])->assertStatus(403);
    }

    public function test_a_failing_contribution_is_isolated(): void
    {
        $company = $this->company();
        $owner = $this->owner($company);
        $this->shipments($company, $owner);

        $this->app->bind(DocumentsHomePriorities::class, fn () => new class implements HomePriorityProvider
        {
            public function priorities(HomeContext $home): array
            {
                throw new \RuntimeException('documents source down');
            }
        });

        $res = $this->priorities($owner, $company);
        $this->assertSame(['core.documents'], $res['unavailable']);
        $this->assertContains('shipments_late', array_column($res['priorities'], 'key'), 'the rest of the home is unaffected');
    }

    public function test_contract_no_business_in_core_and_labels_in_both_locales(): void
    {
        $core = collect(glob(app_path('Core/Home/*.php')))->map(fn ($f) => file_get_contents($f))->implode("\n");
        foreach (['shipment', 'workforce', 'document_', 'leave', 'logistique'] as $business) {
            $this->assertStringNotContainsStringIgnoringCase("'{$business}", $core, 'the Core assembles, modules contribute');
        }

        $providers = collect(ModuleRegistry::forScope('company'))->flatMap(fn ($m) => $m->capabilities->priorityProviders)->all();
        $this->assertNotEmpty($providers);
        $keys = ['leave_to_approve', 'timesheets_to_approve', 'time_anomalies', 'signatures_pending', 'payroll_to_control', 'onboarding_incomplete'];
        foreach ($providers as $class) {
            $this->assertContains(HomePriorityProvider::class, class_implements($class));
            preg_match_all("/HomePriority::make\\('([a-z_]+)'/", file_get_contents((new \ReflectionClass($class))->getFileName()), $m);
            $keys = array_merge($keys, $m[1]);
        }
        foreach (['fr', 'en'] as $locale) {
            $labels = json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/{$locale}.json")), true)['home']['priorities'];
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $labels, "home.priorities.{$key} ({$locale})");
            }
        }
    }
}
