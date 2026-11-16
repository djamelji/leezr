<?php

namespace Tests\Feature;

use App\Company\RBAC\CapabilityAccessService;
use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-807 — permission rationalisation without silent change : explicit server scopes on Shipments
 * (permission + resource rule), inert permissions kept and labelled, composite capabilities never grant the
 * rights of an inactive module, quote = what the activation really bills. 200/201 always checked with state.
 */
class Adr807PermissionRationalizationTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private const INERT = ['documentation.view', 'jobdomain.manage', 'shipments.delete', 'shipments.manage_fields', 'workforce.admin',
        'workforce.leave_adjust', 'workforce_documents.manage_templates', 'workforce_documents.sign'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function company(string $tag, array $modules, bool $testActivation = true): array
    {
        Jobdomain::where('key', 'logistique')->update(['default_modules' => $modules]);
        $c = Company::create(['name' => "D807 $tag", 'slug' => 'd807-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        if ($testActivation) {
            $this->activateCompanyModules($c);
        }
        foreach ($modules as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $this->setUpCompanyRbac($c);
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$c, $owner];
    }

    private function as(User $u, Company $c)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function member(Company $c, array $perms, bool $admin = false): User
    {
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r'.uniqid(), 'name' => 'R', 'is_administrative' => $admin]);
        $role->syncPermissionsSafe(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        $c->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function shipment(Company $c, ?int $assignee, string $status = Shipment::STATUS_PLANNED): Shipment
    {
        $creator = $c->memberships()->where('role', 'owner')->value('user_id');

        return Shipment::withoutGlobalScopes()->create(['company_id' => $c->id, 'created_by_user_id' => $creator, 'assigned_to_user_id' => $assignee,
            'reference' => 'S-'.uniqid(), 'status' => $status]);
    }

    // ─── §1 Shipments ───────────────────────────────────────────────────────────────────────────

    public function test_driver_with_cumulated_permissions_never_touches_an_unassigned_shipment(): void
    {
        [$c] = $this->company('S', ['logistics_shipments']);
        // Every shipment permission EXCEPT the collective view : view_own + status + create + assign.
        $driver = $this->member($c, ['shipments.view_own', 'shipments.manage_status', 'shipments.create', 'shipments.assign'], true);
        $mine = $this->shipment($c, $driver->id);
        $other = $this->shipment($c, null);

        $this->as($driver, $c)->putJson("/api/shipments/{$other->id}/status", ['status' => 'in_transit'])->assertForbidden();
        $this->as($driver, $c)->postJson("/api/shipments/{$other->id}/assign", ['user_id' => $driver->id])->assertForbidden(); // no self-assignment
        $this->as($driver, $c)->putJson("/api/my-deliveries/{$other->id}/status", ['status' => 'in_transit'])->assertNotFound();
        $this->assertSame(Shipment::STATUS_PLANNED, $other->fresh()->status);
        $this->assertNull($other->fresh()->assigned_to_user_id);

        $this->as($driver, $c)->putJson("/api/my-deliveries/{$mine->id}/status", ['status' => 'in_transit'])->assertOk();
        $this->assertSame(Shipment::STATUS_IN_TRANSIT, $mine->fresh()->status);
        $this->as($driver, $c)->postJson('/api/shipments', ['destination_address' => 'X'])->assertStatus(201); // creating stays allowed
        $this->assertSame(3, Shipment::withoutGlobalScopes()->where('company_id', $c->id)->count());
    }

    public function test_status_permission_alone_or_view_own_alone_is_not_enough(): void
    {
        [$c] = $this->company('S2', ['logistics_shipments']);
        $statusOnly = $this->member($c, ['shipments.manage_status']);
        $viewOwnOnly = $this->member($c, ['shipments.view_own']);
        $s1 = $this->shipment($c, $statusOnly->id);
        $s2 = $this->shipment($c, $viewOwnOnly->id);

        $this->as($statusOnly, $c)->putJson("/api/my-deliveries/{$s1->id}/status", ['status' => 'in_transit'])->assertForbidden(); // ADR-807 : view_own required
        $this->as($viewOwnOnly, $c)->putJson("/api/my-deliveries/{$s2->id}/status", ['status' => 'in_transit'])->assertForbidden();
        $this->as($viewOwnOnly, $c)->getJson("/api/my-deliveries/{$s2->id}")->assertOk()->assertJsonPath('shipment.id', $s2->id);
        $this->assertSame(Shipment::STATUS_PLANNED, $s1->fresh()->status);
        $this->assertSame(Shipment::STATUS_PLANNED, $s2->fresh()->status);
    }

    public function test_reassignment_moves_the_right_and_managers_keep_collective_control(): void
    {
        [$c] = $this->company('S3', ['logistics_shipments']);
        $manager = $this->member($c, ['shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign']);
        $a = $this->member($c, ['shipments.view_own', 'shipments.manage_status']);
        $b = $this->member($c, ['shipments.view_own', 'shipments.manage_status']);
        $s = $this->shipment($c, $a->id);

        $this->as($manager, $c)->postJson("/api/shipments/{$s->id}/assign", ['user_id' => $b->id])->assertOk();
        $this->assertSame($b->id, $s->fresh()->assigned_to_user_id);
        $this->as($a, $c)->putJson("/api/my-deliveries/{$s->id}/status", ['status' => 'in_transit'])->assertNotFound(); // former driver
        $this->as($a, $c)->getJson("/api/my-deliveries/{$s->id}")->assertNotFound();
        $this->assertSame(Shipment::STATUS_PLANNED, $s->fresh()->status);
        $this->as($b, $c)->putJson("/api/my-deliveries/{$s->id}/status", ['status' => 'in_transit'])->assertOk();
        $this->assertSame(Shipment::STATUS_IN_TRANSIT, $s->fresh()->status);

        // Collective scope : any shipment, assigned or not.
        $free = $this->shipment($c, null);
        $this->as($manager, $c)->putJson("/api/shipments/{$free->id}/status", ['status' => 'in_transit'])->assertOk();
        $this->assertSame(Shipment::STATUS_IN_TRANSIT, $free->fresh()->status);
        $this->as($manager, $c)->putJson("/api/shipments/{$s->id}/status", ['status' => 'delivered'])->assertOk();
        $this->assertSame(Shipment::STATUS_DELIVERED, $s->fresh()->status);
        $this->as($b, $c)->putJson("/api/my-deliveries/{$s->id}/status", ['status' => 'canceled'])->assertStatus(422); // terminal : workflow kept
    }

    public function test_assignment_rule_is_company_scoped(): void
    {
        [$a] = $this->company('TA', ['logistics_shipments']);
        [$b] = $this->company('TB', ['logistics_shipments']);
        $driver = $this->member($a, ['shipments.view_own', 'shipments.manage_status', 'shipments.view']);
        $b->memberships()->create(['user_id' => $driver->id, 'role' => 'user']); // same person, member of B without rights
        $foreign = $this->shipment($b, $driver->id); // assigned to him, but in company B

        $this->as($driver, $a)->putJson("/api/my-deliveries/{$foreign->id}/status", ['status' => 'in_transit'])->assertNotFound();
        $this->as($driver, $a)->putJson("/api/shipments/{$foreign->id}/status", ['status' => 'in_transit'])->assertNotFound();
        $this->as($driver, $b)->putJson("/api/my-deliveries/{$foreign->id}/status", ['status' => 'in_transit'])->assertForbidden(); // no right in B
        $this->assertSame(Shipment::STATUS_PLANNED, $foreign->fresh()->status);
    }

    // ─── §2 / §5 Inert permissions, composite capabilities ──────────────────────────────────────

    public function test_inert_permissions_are_kept_labelled_and_still_resolvable(): void
    {
        [$c] = $this->company('I', ['logistics_shipments']);
        [, $inert] = CapabilityAccessService::permissionIndex();
        sort($inert);
        $this->assertSame(self::INERT, $inert);
        foreach (self::INERT as $k) {
            $this->assertNotNull(CompanyPermission::where('key', $k)->first(), "$k kept in the catalog");
        }
        $caps = collect((new CapabilityAccessService($c))->capabilities(null))->keyBy('key');
        $this->assertSame(['workforce_documents.manage_templates', 'workforce_documents.sign'], $caps['workforce_documents.management']['inert_permissions']);
        $this->assertSame(['workforce.admin'], $caps['workforce.hr_management']['inert_permissions']);

        // Old RBAC contracts stay valid : every bundle referenced by the historical jobdomain roles still resolves.
        foreach (JobdomainRegistry::definitions() as $jd) {
            foreach ($jd['default_roles'] ?? [] as $role) {
                $bundles = $role['bundles'] ?? [];
                $this->assertCount(count($bundles), array_intersect($bundles, ModuleRegistry::allBundleKeys()), 'bundle removed : '.implode(',', array_diff($bundles, ModuleRegistry::allBundleKeys())));
            }
        }
    }

    public function test_composite_capability_never_grants_an_inactive_module_and_keeps_historical_rights(): void
    {
        [$c, $owner] = $this->company('C', ['workforce']);
        $chef = CompanyRole::create(['company_id' => $c->id, 'key' => 'chef', 'name' => 'Chef', 'is_administrative' => true]);
        $hist = CompanyRole::create(['company_id' => $c->id, 'key' => 'hist', 'name' => 'Hist', 'is_administrative' => true]);
        $hist->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.leave_approve', 'workforce.leave_request'])->pluck('id')->all()); // historical
        $caps = collect($this->as($owner, $c)->getJson('/api/company/role-capabilities/workforce')->assertOk()->json('capabilities'))->keyBy('key');
        $this->assertEqualsCanonicalizing(['workforce_leave', 'workforce_planning'], $caps['workforce.manager']['dormant_modules']);

        $grant = fn ($role, $grant) => [['role_id' => $role->id, 'capability' => 'workforce.manager', 'grant' => $grant]];
        $fp = $this->as($owner, $c)->postJson('/api/company/role-capabilities/workforce/preview', ['changes' => $grant($chef, true)])->assertOk()->json('fingerprint');
        $this->as($owner, $c)->putJson('/api/company/role-capabilities/workforce', ['changes' => $grant($chef, true), 'fingerprint' => $fp])->assertOk();
        $keys = $chef->fresh()->permissions()->pluck('key')->all();
        $this->assertContains('workforce.timesheet_approve', $keys);
        $this->assertEmpty(array_filter($keys, fn ($k) => str_starts_with($k, 'workforce.leave') || str_starts_with($k, 'workforce.planning')), 'no dormant right');

        // Activating Leave later grants nothing to the chef.
        CompanyModuleActivationReason::create(['company_id' => $c->id, 'module_key' => 'workforce_leave', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->assertNotContains('workforce.leave_approve', $chef->fresh()->permissions()->pluck('key')->all());

        // Revoking on a role with historical Leave rights (Leave now active ⇒ part of the capability) is explicit and previewed.
        $hist->syncPermissionsSafe(CompanyPermission::whereIn('key', ['workforce.leave_approve', 'workforce.leave_request', 'workforce.view'])->pluck('id')->all());
        CompanyModuleActivationReason::where('company_id', $c->id)->where('module_key', 'workforce_leave')->delete();
        $fp = $this->as($owner, $c)->postJson('/api/company/role-capabilities/workforce/preview', ['changes' => $grant($hist, false)])->json('fingerprint');
        $this->as($owner, $c)->putJson('/api/company/role-capabilities/workforce', ['changes' => $grant($hist, false), 'fingerprint' => $fp, 'confirm_affected' => true])->assertOk();
        $this->assertEqualsCanonicalizing(['workforce.leave_approve', 'workforce.leave_request'], $hist->fresh()->permissions()->pluck('key')->all(),
            'dormant (inactive module) historical rights are never removed silently');
    }

    // ─── §3 Quote with paid dependencies ────────────────────────────────────────────────────────

    public function test_quote_covering_inactive_requirements_equals_what_activation_bills(): void
    {
        $this->seed(\Database\Seeders\ModuleProductConfigSeeder::class);
        PlatformModule::where('key', 'workforce_signature')->update(['is_listed' => true, 'is_sellable' => true,
            'addon_pricing' => ['pricing_model' => 'flat', 'pricing_metric' => 'none', 'pricing_params' => ['price_monthly' => 0]]]);
        // Real engine path (the test helper marks every module as already enabled, which skips billing).
        Jobdomain::where('key', 'logistique')->update(['default_modules' => []]);
        [$c, $owner] = $this->company('Q', [], false);
        $this->assertTrue(ModuleActivationEngine::enable($c, 'workforce')['success']); // per-seat : 0 € on pro
        $this->assertSame(0, CompanyAddonSubscription::where('company_id', $c->id)->count());

        // Legacy quote (requested module only) : the paid requirement is listed as « included », total 0.
        $legacy = $this->as($owner, $c)->getJson('/api/modules/quote?keys[]=workforce_signature')->assertOk()->json();
        $this->assertSame(0, $legacy['total']);
        $this->assertContains('workforce_documents', array_column($legacy['included'], 'key'));

        // ADR-807 dialog quote : requested + inactive requirements → the real cost.
        $q = $this->as($owner, $c)->getJson('/api/modules/quote?keys[]=workforce_signature&keys[]=workforce_documents')->assertOk()->json();
        $lines = collect($q['lines'])->keyBy('key');
        $this->assertSame(900, $lines['workforce_documents']['amount']);
        $this->assertSame(900, $q['total']);
        $this->assertSame(['workforce'], array_column($q['included'], 'key'), 'already active');

        // Real activation (same engine as the screen) bills exactly that.
        $r = ModuleActivationEngine::enable($c, 'workforce_signature');
        $this->assertTrue($r['success']);
        $this->assertEqualsCanonicalizing(['workforce_documents', 'workforce_signature'], $r['data']['activated']);
        $this->assertSame(900, (int) CompanyAddonSubscription::where('company_id', $c->id)->sum('amount_cents'));
        $this->assertSame(['workforce_documents'], CompanyAddonSubscription::where('company_id', $c->id)->pluck('module_key')->all());

        // Deactivating the requested module also deactivates the requirement added only for it (what the dialog says).
        $off = ModuleActivationEngine::disable($c, 'workforce_signature');
        $this->assertEqualsCanonicalizing(['workforce_documents', 'workforce_signature'], $off['data']['deactivated']);
        $this->assertTrue(CompanyModuleActivationReason::where('company_id', $c->id)->where('module_key', 'workforce')->exists(), 'directly active module kept');
    }

    public function test_ui_contracts_quote_and_included_modules(): void
    {
        $src = file_get_contents(resource_path('js/pages/company/modules/index.vue'));
        $this->assertStringContainsString('fetchQuote([module.key, ...missing])', $src, 'quote covers inactive requirements');
        $this->assertStringContainsString('paidMissing', $src, 'free module with a paid requirement goes through the quote');
        $this->assertStringContainsString('module-included-locked-', $src);
        $this->assertStringContainsString('quote-deactivation', $src);
        foreach (['fr', 'en', 'ar'] as $l) {
            $cm = json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/$l.json")), true)['companyModules'];
            foreach (['quoteRequested', 'quoteAdded', 'quoteAlreadyActive', 'quoteDeactivationWithAdded', 'quoteBillingStopsEndOfPeriod', 'quoteBillingStopsNow', 'includedLocked'] as $k) {
                $this->assertNotEmpty($cm[$k] ?? null, "$l companyModules.$k");
            }
        }
    }
}
