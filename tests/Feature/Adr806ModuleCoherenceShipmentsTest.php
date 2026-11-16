<?php

namespace Tests\Feature;

use App\Company\RBAC\CapabilityAccessService;
use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Events\ModuleEnabled;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\Shipment;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-806 — module coherence + Logistics pilot (logistics_shipments) on the common assistant.
 * Positive checks assert 200/201 AND the state change ; a 404/422 never counts as a success.
 */
class Adr806ModuleCoherenceShipmentsTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private Company $company;

    private User $owner;

    private CompanyRole $dispatch;

    private CompanyRole $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Jobdomain::where('key', 'logistique')->update(['default_modules' => ['logistics_shipments']]);
        [$this->company, $this->owner] = $this->makeCompany('A');
        $this->dispatch = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'dispatch', 'name' => 'Dispatch', 'is_administrative' => true]);
        $this->driver = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'driver', 'name' => 'Driver', 'is_administrative' => false]);
    }

    private function makeCompany(string $tag): array
    {
        $c = Company::create(['name' => "D806 $tag", 'slug' => 'd806-'.strtolower($tag).'-'.uniqid(), 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($c);
        CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => 'logistics_shipments'], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->setUpCompanyRbac($c);
        $owner = User::factory()->create();
        $c->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$c, $owner];
    }

    private function as(User $u, ?Company $c = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->company)->id]);
    }

    private function member(CompanyRole $role): User
    {
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function perms(CompanyRole $r): array
    {
        return $r->fresh()->permissions()->pluck('key')->sort()->values()->all();
    }

    private function apply(array $changes)
    {
        $fp = $this->as($this->owner)->postJson('/api/modules/logistics_shipments/setup/access/preview', ['changes' => $changes])->assertOk()->json('fingerprint');

        return $this->as($this->owner)->putJson('/api/modules/logistics_shipments/setup/access', ['changes' => $changes, 'fingerprint' => $fp]);
    }

    private function shipment(?int $assignee = null, string $status = Shipment::STATUS_PLANNED, ?Company $c = null): Shipment
    {
        $c ??= $this->company;

        return Shipment::withoutGlobalScopes()->create(['company_id' => $c->id, 'created_by_user_id' => $this->owner->id, 'assigned_to_user_id' => $assignee,
            'reference' => 'S-'.uniqid(), 'status' => $status]);
    }

    public function test_shipments_assistant_access_only_and_activation_grants_nothing(): void
    {
        ModuleEnabled::dispatch($this->company, 'logistics_shipments');
        $before = [$this->perms($this->dispatch), $this->perms($this->driver)];
        $s = $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->assertOk()->json();

        $this->assertSame(['access'], $s['steps'], 'the module executes no setting : no settings step');
        $this->assertSame([$this->perms($this->dispatch), $this->perms($this->driver)], $before, 'nothing granted at activation');
        $caps = collect($s['capabilities'])->keyBy('key');
        $this->assertSame('collective', $caps['shipments.operations']['scope']);
        $this->assertSame('own', $caps['shipments.delivery']['scope']);
        $this->assertTrue($caps['shipments.administration']['unwired'], 'custom fields / deletion do not exist');
        $this->assertFalse($caps['shipments.operations']['unwired']);
        $ready = collect($s['readiness'])->keyBy('key');
        $this->assertFalse($ready['access_decided']['ok']);
        $this->assertFalse($ready['delivery_assigned']['required']);

        // Not ready before the access step : confirm refused, state unchanged.
        $this->as($this->owner)->postJson('/api/modules/logistics_shipments/setup/confirm', ['revision' => $s['revision']])->assertStatus(422);
        $this->assertNotSame('ready', $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->json('status'));

        $this->apply([
            ['role_id' => $this->dispatch->id, 'capability' => 'shipments.operations', 'grant' => true],
            ['role_id' => $this->driver->id, 'capability' => 'shipments.delivery', 'grant' => true],
        ])->assertOk();
        $this->assertSame(['shipments.assign', 'shipments.create', 'shipments.manage_status', 'shipments.view'], $this->perms($this->dispatch));
        $this->assertSame(['shipments.manage_status', 'shipments.view_own'], $this->perms($this->driver));

        $rev = $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->json('revision');
        $this->as($this->owner)->postJson('/api/modules/logistics_shipments/setup/confirm', ['revision' => $rev])->assertOk();
        $s = $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->json();
        $this->assertSame('ready', $s['status']);
        $this->assertTrue(collect($s['readiness'])->firstWhere('key', 'delivery_assigned')['ok']);

        // Access changed in the assistant ⇒ the setup reopens (in progress), never a silent « ready » ;
        // changed elsewhere (Équipe → Rôles) ⇒ « needs review » (fingerprint).
        $this->apply([['role_id' => $this->driver->id, 'capability' => 'shipments.delivery', 'grant' => false]])->assertOk();
        $this->assertSame('in_progress', $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->json('status'));
        $rev = $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->json('revision');
        $this->as($this->owner)->postJson('/api/modules/logistics_shipments/setup/confirm', ['revision' => $rev])->assertOk();
        $this->dispatch->syncPermissionsSafe(CompanyPermission::whereIn('key', ['shipments.view'])->pluck('id')->all());
        $s = $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->json();
        $this->assertSame('needs_review', $s['status']);
        $this->assertContains('access_changed', $s['stale_reasons']);
    }

    public function test_existing_companies_are_legacy_not_reconfigured(): void
    {
        // Active before ADR-806 and never opened in the assistant : no row, no rights change, status legacy.
        $before = $this->perms($this->dispatch);
        $this->assertSame('legacy', $this->as($this->owner)->getJson('/api/modules/logistics_shipments/setup')->json('status'));
        $this->assertSame($before, $this->perms($this->dispatch));
    }

    public function test_operational_role_cannot_receive_administration_and_member_cannot_configure(): void
    {
        $p = $this->as($this->owner)->postJson('/api/modules/logistics_shipments/setup/access/preview',
            ['changes' => [['role_id' => $this->driver->id, 'capability' => 'shipments.administration', 'grant' => true]]])->assertOk()->json('plan.0');
        $this->assertSame('operational_role', $p['blocked']);

        $driver = $this->member($this->driver);
        $this->as($driver)->getJson('/api/modules/logistics_shipments/setup')->assertForbidden();
        $this->as($driver)->getJson('/api/modules/logistics_shipments/deactivation-impact')->assertForbidden();
    }

    public function test_driver_operates_only_assigned_shipments_and_cannot_change_others_status(): void
    {
        $this->apply([
            ['role_id' => $this->dispatch->id, 'capability' => 'shipments.operations', 'grant' => true],
            ['role_id' => $this->driver->id, 'capability' => 'shipments.delivery', 'grant' => true],
        ])->assertOk();
        $driver = $this->member($this->driver);
        $dispatcher = $this->member($this->dispatch);
        $mine = $this->shipment($driver->id);
        $other = $this->shipment(null);

        // Dispatcher : create (201 + row), assign (200 + assignee), status (200 + status).
        $id = $this->as($dispatcher)->postJson('/api/shipments', ['destination_address' => 'Quai 4'])->assertStatus(201)->json('shipment.id');
        $this->assertSame(Shipment::STATUS_DRAFT, Shipment::withoutGlobalScopes()->find($id)->status);
        $this->as($dispatcher)->postJson("/api/shipments/$id/assign", ['user_id' => $driver->id])->assertOk();
        $this->assertSame($driver->id, Shipment::withoutGlobalScopes()->find($id)->assigned_to_user_id);
        $this->as($dispatcher)->putJson("/api/shipments/$id/status", ['status' => 'planned'])->assertOk();
        $this->assertSame('planned', Shipment::withoutGlobalScopes()->find($id)->status);

        // Driver : own list + own status update (200 + state) …
        $ids = collect($this->as($driver)->getJson('/api/my-deliveries')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$mine->id, $id], $ids);
        $this->as($driver)->putJson("/api/my-deliveries/{$mine->id}/status", ['status' => 'in_transit'])->assertOk();
        $this->assertSame('in_transit', $mine->fresh()->status);

        // … but NOT the status of a shipment that is not assigned to them (ADR-806 fix : before, 200).
        $this->as($driver)->putJson("/api/shipments/{$other->id}/status", ['status' => 'in_transit'])->assertForbidden();
        $this->as($driver)->putJson("/api/my-deliveries/{$other->id}/status", ['status' => 'in_transit'])->assertNotFound();
        $this->assertSame(Shipment::STATUS_PLANNED, $other->fresh()->status, 'unchanged');
        $this->as($driver)->getJson('/api/shipments')->assertForbidden();
        $this->as($driver)->postJson('/api/shipments', [])->assertForbidden();
    }

    public function test_tenant_isolation_and_disabled_module(): void
    {
        $this->apply([['role_id' => $this->dispatch->id, 'capability' => 'shipments.operations', 'grant' => true]])->assertOk();
        $dispatcher = $this->member($this->dispatch);
        [$b] = $this->makeCompany('B');
        $foreign = $this->shipment(null, Shipment::STATUS_PLANNED, $b);

        $this->as($dispatcher)->getJson("/api/shipments/{$foreign->id}")->assertNotFound();
        $this->as($dispatcher)->putJson("/api/shipments/{$foreign->id}/status", ['status' => 'in_transit'])->assertNotFound();
        $this->assertSame(Shipment::STATUS_PLANNED, $foreign->fresh()->status);
        $this->as($dispatcher, $b)->getJson('/api/shipments')->assertForbidden(); // not a member of B

        // Deactivation impact : open shipments are kept but their status cannot change (said, not silent).
        $this->shipment(null, Shipment::STATUS_IN_TRANSIT);
        $this->shipment(null, Shipment::STATUS_DELIVERED);
        $impact = $this->as($this->owner)->getJson('/api/modules/logistics_shipments/deactivation-impact')->assertOk()->json();
        $item = collect($impact['items'])->firstWhere('key', 'shipments_in_progress');
        $this->assertSame(1, $item['count']);
        $this->assertFalse($item['continues']);

        CompanyModuleActivationReason::where('company_id', $this->company->id)->where('module_key', 'logistics_shipments')->delete();
        $this->as($dispatcher)->getJson('/api/shipments')->assertForbidden();
        $this->assertSame(2, Shipment::withoutGlobalScopes()->where('company_id', $this->company->id)->count(), 'nothing deleted');

        CompanyModuleActivationReason::create(['company_id' => $this->company->id, 'module_key' => 'logistics_shipments', 'reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        $this->as($dispatcher)->getJson('/api/shipments')->assertOk()->assertJsonCount(2, 'data');
        $this->assertContains('shipments.view', $this->perms($this->dispatch), 'rights kept across reactivation');
    }

    public function test_inert_capabilities_are_flagged_and_never_granted_silently(): void
    {
        $unwired = collect((new CapabilityAccessService($this->company))->capabilities(null))->where('unwired', true)->pluck('key')->sort()->values()->all();
        $this->assertSame(['documentation.access', 'jobdomain.management', 'shipments.administration', 'workforce.leave_adjustment'], $unwired);

        // Each unwired capability guards no route : proof that flagging it hides nothing that works.
        $guarded = [];
        foreach (app('router')->getRoutes() as $r) {
            foreach ($r->gatherMiddleware() as $m) {
                if (is_string($m) && preg_match('/use-permission,(.+)$/', $m, $x)) {
                    $guarded[$x[1]][] = $r->methods()[0].' '.$r->uri();
                }
            }
        }
        foreach (['documentation.view', 'shipments.manage_fields', 'shipments.delete', 'workforce.leave_adjust'] as $p) {
            $this->assertArrayNotHasKey($p, $guarded, "$p guards nothing");
        }
        $this->assertSame(['PUT api/company/jobdomain'], $guarded['jobdomain.manage']);
        $c = $this->as($this->owner)->putJson('/api/company/jobdomain', ['key' => 'logistique']);
        $c->assertStatus(422); // immutable industry : the permission protects an action that never succeeds

        // Permissions still exist and stay on the roles that hold them (no removal without a compatibility plan).
        $this->assertNotNull(CompanyPermission::where('key', 'shipments.delete')->first());
    }

    public function test_every_company_capability_is_translated_fr_en_ar_and_resolvable(): void
    {
        $locales = [];
        foreach (['fr', 'en', 'ar'] as $l) {
            $locales[$l] = json_decode(file_get_contents(resource_path("js/plugins/i18n/locales/$l.json")), true)['permissionCatalog']['bundles'] ?? [];
        }
        foreach (ModuleRegistry::forScope('company') as $m) {
            foreach ($m->bundles as $b) {
                foreach ($locales as $l => $bundles) {
                    $this->assertNotEmpty($bundles[$b['key']]['label'] ?? null, "$l label {$b['key']}");
                    $this->assertNotEmpty($bundles[$b['key']]['hint'] ?? null, "$l hint {$b['key']}");
                }
                $this->assertNotSame($locales['fr'][$b['key']]['label'], $locales['ar'][$b['key']]['label'], "ar is not a copy for {$b['key']}");
            }
        }
        // The keys contain dots : every consumer goes through useCatalogText (te/t on a dotted path never resolve).
        foreach (['components/modules/ModuleCapabilityAccess.vue', 'components/modules/CapabilityChangePreview.vue', 'pages/shared/_PermissionMatrix.vue', 'pages/company/modules/_ModuleSetupWizard.vue'] as $f) {
            $src = file_get_contents(resource_path("js/$f"));
            $this->assertStringNotContainsString('`permissionCatalog.', $src, $f);
            $this->assertStringContainsString('useCatalogText', $src, $f);
        }
        // Module names translated in the three supported locales (selector Équipe → Rôles, assistant, impact).
        $ar = json_decode(file_get_contents(resource_path('js/plugins/i18n/locales/ar.json')), true)['moduleCatalog'] ?? [];
        foreach (array_keys(ModuleRegistry::forScope('company')) as $k) {
            $this->assertNotEmpty($ar[str_replace('.', '_', $k)]['name'] ?? null, "ar module name $k");
        }
        // Included modules are activated at creation WITHOUT event (status « legacy ») : the card must still lead to the assistant.
        $this->assertStringNotContainsString("mod.setup_status !== 'legacy'", file_get_contents(resource_path('js/pages/company/modules/index.vue')));
    }
}
