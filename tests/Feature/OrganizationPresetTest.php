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
use App\Core\Workforce\Department;
use App\Core\Workforce\JobRole;
use App\Modules\Workforce\Organization\OrganizationPreset;
use App\Modules\Workforce\Organization\OrganizationPresetResolver;
use App\Modules\Workforce\Organization\OrganizationPresetService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-636 (W-E) — presets organisationnels : résolution (market/jobdomain/profil), aperçu sans écriture,
 * application non destructive & idempotente, préservation des personnalisations, RBAC, isolation tenant.
 */
class OrganizationPresetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique', 'default_modules' => ['workforce']]);
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        PlatformModule::create(['key' => 'workforce', 'name' => 'Workforce', 'is_enabled_globally' => true]);
        CompanyPermissionCatalog::sync();

        $this->preset('base', null, null, 1, [['key' => 'direction', 'name' => 'Direction', 'parent_key' => null]], [['key' => 'r1', 'title' => 'Dir', 'department_key' => 'direction']]);
        $this->preset('lastmile', 'FR', 'last_mile_delivery', 1, [
            ['key' => 'direction', 'name' => 'Direction', 'parent_key' => null],
            ['key' => 'exploitation', 'name' => 'Exploitation', 'parent_key' => 'direction'],
        ], [['key' => 'drv', 'title' => 'Livreur', 'department_key' => 'exploitation']]);
    }

    private function preset(string $suffix, ?string $market, ?string $profile, int $version, array $depts, array $roles, string $status = 'published'): OrganizationPreset
    {
        return OrganizationPreset::create([
            'key' => "logi_{$suffix}_v{$version}", 'label' => $suffix, 'jobdomain_key' => 'logistique',
            'market_key' => $market, 'org_profile_key' => $profile, 'version' => $version, 'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'structure' => ['departments' => $depts, 'job_roles' => $roles],
        ]);
    }

    private function company(?string $profile = null): Company
    {
        return Company::create(['name' => 'C '.uniqid(), 'slug' => 'c-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'org_profile_key' => $profile]);
    }

    public function test_resolution_profile_wins_over_base(): void
    {
        $r = OrganizationPresetResolver::resolveFor($this->company('last_mile_delivery'));
        $this->assertSame('logi_lastmile_v1', $r->key);
    }

    public function test_resolution_jobdomain_fallback_without_profile(): void
    {
        $r = OrganizationPresetResolver::resolveFor($this->company(null));
        $this->assertSame('logi_base_v1', $r->key, 'sans profil → preset de base jobdomain');
    }

    public function test_higher_version_wins(): void
    {
        $this->preset('lastmile', 'FR', 'last_mile_delivery', 2, [['key' => 'direction', 'name' => 'Direction', 'parent_key' => null]], []);
        $r = OrganizationPresetResolver::resolveFor($this->company('last_mile_delivery'));
        $this->assertSame(2, $r->version);
    }

    public function test_preview_writes_nothing(): void
    {
        $c = $this->company('last_mile_delivery');
        $before = Department::withoutGlobalScopes()->count();
        $p = OrganizationPresetService::preview($c);
        $this->assertTrue($p['has_preset']);
        $this->assertSame(3, $p['summary']['new']); // 2 depts + 1 role
        $this->assertSame($before, Department::withoutGlobalScopes()->count(), 'aucune écriture');
    }

    public function test_apply_creates_and_is_idempotent(): void
    {
        $c = $this->company('last_mile_delivery');
        $a1 = OrganizationPresetService::apply($c);
        $this->assertCount(3, $a1['created']);
        $this->assertSame(2, Department::withoutGlobalScopes()->where('company_id', $c->id)->count());

        $a2 = OrganizationPresetService::apply($c->fresh());
        $this->assertCount(0, $a2['created'], 'rejeu idempotent');
        $this->assertCount(3, $a2['skipped']);
        $this->assertSame(2, Department::withoutGlobalScopes()->where('company_id', $c->id)->count(), 'pas de doublon');
    }

    public function test_hierarchy_is_built(): void
    {
        $c = $this->company('last_mile_delivery');
        OrganizationPresetService::apply($c);
        $dir = Department::withoutGlobalScopes()->where('company_id', $c->id)->where('preset_item_key', 'direction')->first();
        $exp = Department::withoutGlobalScopes()->where('company_id', $c->id)->where('preset_item_key', 'exploitation')->first();
        $this->assertSame($dir->id, $exp->parent_id);
    }

    public function test_preserves_customization_and_company_entities(): void
    {
        $c = $this->company('last_mile_delivery');
        OrganizationPresetService::apply($c);
        // Renommer un dept issu du preset + ajouter un dept Company.
        Department::withoutGlobalScopes()->where('company_id', $c->id)->where('preset_item_key', 'exploitation')->update(['name' => 'Ops']);
        Department::withoutGlobalScopes()->create(['company_id' => $c->id, 'name' => 'Qualité', 'source' => 'company']);

        OrganizationPresetService::apply($c->fresh());

        $this->assertSame('Ops', Department::withoutGlobalScopes()->where('company_id', $c->id)->where('preset_item_key', 'exploitation')->value('name'), 'renommage préservé');
        $this->assertTrue(Department::withoutGlobalScopes()->where('company_id', $c->id)->where('name', 'Qualité')->exists(), 'entité Company préservée');
        $this->assertSame(3, Department::withoutGlobalScopes()->where('company_id', $c->id)->count());
    }

    public function test_name_clash_is_flagged_not_duplicated(): void
    {
        $c = $this->company('last_mile_delivery');
        // La Company a déjà un département « Direction » créé manuellement (homonyme preset).
        Department::withoutGlobalScopes()->create(['company_id' => $c->id, 'name' => 'Direction', 'source' => 'company']);
        $a = OrganizationPresetService::apply($c);
        $this->assertContains('department:direction:name_clash', $a['conflicts']);
        $this->assertSame(1, Department::withoutGlobalScopes()->where('company_id', $c->id)->where('name', 'Direction')->count(), 'pas de doublon de nom');
    }

    public function test_tenant_isolation(): void
    {
        $a = $this->company('last_mile_delivery');
        OrganizationPresetService::apply($a);
        $b = $this->company('last_mile_delivery');
        $this->assertSame(0, Department::withoutGlobalScopes()->where('company_id', $b->id)->count(), 'organisation strictement propre à chaque Company');
    }

    // ── RBAC HTTP ──
    private function userRole(Company $c, array $perms, bool $admin): User
    {
        foreach (['workforce'] as $m) {
            CompanyModuleActivationReason::firstOrCreate(['company_id' => $c->id, 'module_key' => $m], ['reason' => CompanyModuleActivationReason::REASON_DIRECT]);
        }
        $u = User::factory()->create();
        $role = CompanyRole::create(['company_id' => $c->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_system' => false, 'is_administrative' => $admin]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->toArray());
        Membership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'member', 'company_role_id' => $role->id]);

        return $u;
    }

    public function test_apply_requires_manage_permission(): void
    {
        $c = $this->company('last_mile_delivery');
        $viewer = $this->userRole($c, ['workforce.view'], false);
        $this->actingAs($viewer)->postJson('/api/workforce/organization/preset/apply', [], ['X-Company-Id' => $c->id])->assertForbidden();

        $manager = $this->userRole($c, ['workforce.view', 'workforce.manage'], true);
        $this->actingAs($manager)->postJson('/api/workforce/organization/preset/apply', [], ['X-Company-Id' => $c->id])->assertOk();
    }

    public function test_preview_endpoint_is_read_only(): void
    {
        $c = $this->company('last_mile_delivery');
        $viewer = $this->userRole($c, ['workforce.view'], false);
        $before = Department::withoutGlobalScopes()->count();
        $this->actingAs($viewer)->getJson('/api/workforce/organization/preset/preview', ['X-Company-Id' => $c->id])
            ->assertOk()->assertJsonPath('has_preset', true);
        $this->assertSame($before, Department::withoutGlobalScopes()->count());
    }

    public function test_set_profile_validates_against_jobdomain(): void
    {
        $c = $this->company();
        $manager = $this->userRole($c, ['workforce.view', 'workforce.manage'], true);
        $this->actingAs($manager)->putJson('/api/workforce/organization/profile', ['org_profile_key' => 'not_a_profile'], ['X-Company-Id' => $c->id])->assertStatus(422);
        $this->actingAs($manager)->putJson('/api/workforce/organization/profile', ['org_profile_key' => 'last_mile_delivery'], ['X-Company-Id' => $c->id])->assertOk();
        $this->assertSame('last_mile_delivery', $c->fresh()->org_profile_key);
    }
}
