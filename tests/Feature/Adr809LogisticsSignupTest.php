<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\Setup\PendingModuleAccess;
use App\Core\Presets\PresetApplication;
use App\Core\Presets\PresetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-809 — a new Logistics company signs up through the real registration endpoint (versioned path), gets its
 * minimal business configuration, confirms the prepared access in ONE grouped decision and starts working.
 * No right is granted silently, no paid module activated, the historical path stays for other jobdomains.
 */
class Adr809LogisticsSignupTest extends TestCase
{
    use \Tests\Support\PublishesStartingLibrary;

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['core.theme', 'core.members', 'core.settings', 'core.documents', 'logistics_shipments', 'core.roles']]);
        $rbac = PresetService::createDraft('rbac', ['family_key' => 'logistique_rbac', 'label' => 'Logistique', 'jobdomain_key' => 'logistique', 'content' => ['roles' => [
            ['key' => 'owner', 'name' => 'Propriétaire', 'is_administrative' => true, 'bundles' => ['members.team_management', 'roles.governance', 'modules.management', 'shipments.operations']],
            ['key' => 'driver', 'name' => 'Chauffeur-livreur', 'bundles' => ['members.team_view', 'settings.company_info', 'shipments.delivery']],
            ['key' => 'dispatcher', 'name' => 'Répartiteur', 'is_administrative' => true, 'bundles' => ['shipments.operations']],
        ]]], null);
        PresetService::publish($rbac, null);
        PresetService::setRecommendations('logistique', [$this->libraryLink(), ['kind' => 'rbac', 'family_key' => 'logistique_rbac']]);
        config(['jobdomains.versioned_init.enabled' => true, 'jobdomains.versioned_init.jobdomains' => ['logistique']]);
    }

    private function register(string $email, string $jobdomain = 'logistique'): Company
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web'); // the previous request's guard must not leak (ADR-801.1)
        $id = $this->postJson('/api/register', ['first_name' => 'R', 'last_name' => 'U', 'email' => $email, 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Reg '.$email, 'jobdomain_key' => $jobdomain, 'plan_key' => 'starter'] + ($jobdomain === 'logistique' ? ['dynamic_fields' => ['siret' => '12345678901234']] : []))->assertCreated()->json('company.id');

        return Company::findOrFail($id);
    }

    private function as(User $u, Company $c)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => $c->id]);
    }

    private function member(Company $c, string $roleKey): User
    {
        $u = User::factory()->create();
        $c->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => CompanyRole::where('company_id', $c->id)->where('key', $roleKey)->value('id')]);

        return $u;
    }

    public function test_signup_grouped_confirmation_then_first_delivery(): void
    {
        $c = $this->register('owner809@example.test');
        $owner = User::where('email', 'owner809@example.test')->sole();
        $this->assertSame(2, PresetApplication::where('company_id', $c->id)->count(), 'versioned path, traced (library + RBAC, ADR-820)');
        $this->assertEqualsCanonicalizing(['driver', 'owner'], CompanyRole::where('company_id', $c->id)->pluck('key')->all(), 'management role awaits a decision');
        $this->assertSame(0, \DB::table('company_addon_subscriptions')->where('company_id', $c->id)->count(), 'no paid module');
        $driver = $this->member($c, 'driver');
        $driverRole = CompanyRole::where('company_id', $c->id)->where('key', 'driver')->sole();
        $stored = $driverRole->permissions()->pluck('key')->sort()->values()->all();

        // Before : the operational role has NO effective right ; the owner's home says so.
        $this->as($driver, $c)->getJson('/api/my-deliveries')->assertForbidden();
        $this->assertSame([], $driverRole->fresh()->effectivePermissionKeys());
        $prio = collect($this->as($owner, $c)->getJson('/api/dashboard/priorities')->assertOk()->json('priorities'))->firstWhere('key', 'access_to_confirm');
        $this->assertSame(1, $prio['count']);
        $this->assertSame(null, collect($this->as($driver, $c)->getJson('/api/dashboard/priorities')->json('priorities'))->firstWhere('key', 'access_to_confirm'));

        // Grouped review : roles, business capabilities, sensitive apart, fingerprint.
        $review = $this->as($owner, $c)->getJson('/api/company/pending-access')->assertOk()->json();
        $this->assertCount(1, $review['roles']);
        $caps = collect($review['roles'][0]['capabilities']);
        $this->assertEqualsCanonicalizing(['members.team_view', 'settings.company_info', 'shipments.delivery'], $caps->pluck('key')->all());
        $this->assertFalse($caps->contains('sensitive', true));
        $this->assertNull($review['roles'][0]['blocked']);
        $this->as($driver, $c)->postJson('/api/company/pending-access/confirm', ['fingerprint' => $review['fingerprint']])->assertForbidden();

        // A stale review (something changed since display) is refused : nothing unseen is confirmed.
        $this->as($owner, $c)->postJson('/api/company/pending-access/confirm', ['fingerprint' => str_repeat('0', 40)])->assertStatus(409);
        $this->assertNotEmpty(PendingModuleAccess::suspendedFor($driverRole->fresh()));

        $this->as($owner, $c)->postJson('/api/company/pending-access/confirm', ['fingerprint' => $review['fingerprint']])->assertOk()->assertJsonCount(0, 'roles');
        $this->assertSame($stored, $driverRole->fresh()->permissions()->pluck('key')->sort()->values()->all(), 'no right added, none removed');
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $c->id, 'action' => 'role.pending_confirmed']);
        $this->assertNull(collect($this->as($owner, $c)->getJson('/api/dashboard/priorities')->json('priorities'))->firstWhere('key', 'access_to_confirm'));

        // Start working : first shipment, assignment, delivery ; forbidden accesses stay forbidden.
        $id = $this->as($owner, $c)->postJson('/api/shipments', ['destination_address' => 'Quai 1'])->assertStatus(201)->json('shipment.id');
        $this->as($owner, $c)->postJson("/api/shipments/$id/assign", ['user_id' => $driver->id])->assertOk();
        $this->as($owner, $c)->putJson("/api/shipments/$id/status", ['status' => 'planned'])->assertOk();
        $this->as($driver, $c)->putJson("/api/my-deliveries/$id/status", ['status' => 'in_transit'])->assertOk();
        $this->as($driver, $c)->putJson("/api/my-deliveries/$id/status", ['status' => 'delivered'])->assertOk();
        $this->assertSame(Shipment::STATUS_DELIVERED, Shipment::withoutGlobalScopes()->find($id)->status);
        $this->as($driver, $c)->getJson('/api/shipments')->assertForbidden();
        $this->as($driver, $c)->getJson('/api/company/roles')->assertForbidden();
        $this->as($driver, $c)->getJson('/api/modules/logistics_shipments/setup')->assertForbidden();
    }

    public function test_grouped_confirmation_respects_delegation_and_other_jobdomains_stay_historical(): void
    {
        $c = $this->register('owner809b@example.test');
        // A non-owner administrator without the shipment rights cannot confirm them (role shown as blocked).
        $admin = CompanyRole::create(['company_id' => $c->id, 'key' => 'admin', 'name' => 'Admin', 'is_administrative' => true]);
        $admin->syncPermissionsSafe(CompanyPermission::whereIn('key', ['roles.view', 'roles.manage'])->pluck('id')->all());
        $u = User::factory()->create();
        $c->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $admin->id]);
        $review = $this->as($u, $c)->getJson('/api/company/pending-access')->assertOk()->json();
        $this->assertSame('beyond_your_rights', $review['roles'][0]['blocked']);
        $out = $this->as($u, $c)->postJson('/api/company/pending-access/confirm', ['fingerprint' => $review['fingerprint']])->assertOk()->json();
        $this->assertCount(0, $out['confirmed']);
        $this->assertCount(1, $out['roles'], 'still pending');

        // Another jobdomain, not listed : historical path (no preset application, rights effective as before).
        Jobdomain::create(['key' => 'other809', 'label' => 'Autre', 'is_active' => true, 'default_modules' => ['core.members'],
            'default_roles' => ['owner' => ['name' => 'Owner', 'is_administrative' => true, 'bundles' => ['members.team_management']]]]);
        // Logistics Only : a jobdomain not offered for sign-up is refused server-side ; Platform opens it explicitly.
        $this->postJson('/api/register', ['first_name' => 'R', 'last_name' => 'U', 'email' => 'refused809@example.test', 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Refused', 'jobdomain_key' => 'other809', 'plan_key' => 'starter'])->assertStatus(422)->assertJsonValidationErrors('jobdomain_key');
        Jobdomain::where('key', 'other809')->update(['signup_available' => true]);
        $o = $this->register('owner809c@example.test', 'other809');
        $this->assertSame(0, PresetApplication::where('company_id', $o->id)->count());
        $this->assertSame([], PendingModuleAccess::summary($o, null)['roles']);
    }
}
