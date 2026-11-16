<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Presets\JobdomainPresetLink;
use App\Core\Presets\PresetService;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-803 (prerequisite) — a preset FOLLOWED by another JobDomain is never changed for it silently :
 * explicit `shared` (publish for all followers) or `variant` (independent family for this domain).
 */
class Adr803SharedPresetPublicationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    private Jobdomain $a;

    private Jobdomain $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $role = PlatformRole::create(['key' => 'jd_'.uniqid(), 'name' => 'JD']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['manage_jobdomains'])->pluck('id')->all());
        $this->admin = PlatformUser::create(['first_name' => 'A', 'last_name' => 'B', 'email' => uniqid().'@p.test', 'password' => 'P@ssw0rd!Strong']);
        $this->admin->roles()->attach($role->id);
        $this->a = Jobdomain::where('key', 'logistique')->first() ?? Jobdomain::create(['key' => 'logistique', 'label' => 'Logistique']);
        $this->b = Jobdomain::create(['key' => 'beta_services', 'label' => 'Beta']);

        $v1 = PresetService::createDraft('rbac', ['family_key' => 'logistique_rbac', 'label' => 'Rôles', 'content' => ['roles' => [['key' => 'chef', 'name' => 'Chef', 'is_administrative' => true, 'bundles' => ['workforce.team']]]]], null);
        PresetService::publish($v1, null);
        PresetService::setRecommendations('logistique', [['kind' => 'rbac', 'family_key' => 'logistique_rbac']]);
        PresetService::setRecommendations('beta_services', [['kind' => 'rbac', 'family_key' => 'logistique_rbac']]); // B follows A's family
    }

    private function draftFromB(): int
    {
        return $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$this->b->id}/presets/rbac/draft")->assertOk()->json('draft.id');
    }

    private function effectiveFor(string $jd): ?int
    {
        return collect(PresetService::recommendationsFor($jd))->firstWhere('kind', 'rbac')['effective_version'] ?? null;
    }

    public function test_publication_of_a_followed_family_requires_an_explicit_choice(): void
    {
        $id = $this->draftFromB();
        $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$this->b->id}/presets/rbac/{$id}/publish")
            ->assertStatus(409)->assertJsonPath('code', 'shared_preset')->assertJsonPath('shared_with', ['logistique']);
        $this->assertSame(1, $this->effectiveFor('logistique'), 'A unchanged');
    }

    public function test_variant_leaves_the_other_domain_untouched(): void
    {
        $id = $this->draftFromB();
        $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$this->b->id}/presets/rbac/{$id}/publish", ['mode' => 'variant'])->assertOk();
        $this->assertSame(1, $this->effectiveFor('logistique'));
        $bLink = JobdomainPresetLink::where('jobdomain_key', 'beta_services')->where('kind', 'rbac')->first();
        $this->assertNotSame('logistique_rbac', $bLink->family_key);
        $this->assertSame(1, $this->effectiveFor('beta_services'));
    }

    public function test_shared_publication_is_explicit_and_audited(): void
    {
        $id = $this->draftFromB();
        $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$this->b->id}/presets/rbac/{$id}/publish", ['mode' => 'shared'])->assertOk();
        $this->assertSame(2, $this->effectiveFor('logistique'));
        $this->assertSame(2, $this->effectiveFor('beta_services'));
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'presets.shared_publication']);
    }

    public function test_catalog_cannot_bypass_the_rule(): void
    {
        $id = $this->draftFromB();
        $this->actingAs($this->admin, 'platform')->postJson("/api/platform/presets/rbac/{$id}/publish")->assertStatus(409)->assertJsonPath('code', 'shared_preset');
        $this->assertSame(1, $this->effectiveFor('logistique'));
    }

    public function test_pinned_followers_are_not_concerned(): void
    {
        PresetService::setRecommendations('beta_services', [['kind' => 'rbac', 'family_key' => 'logistique_rbac', 'pinned_version' => 1]]);
        $id = $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$this->a->id}/presets/rbac/draft")->assertOk()->json('draft.id');
        $this->actingAs($this->admin, 'platform')->postJson("/api/platform/jobdomains/{$this->a->id}/presets/rbac/{$id}/publish")->assertOk();
        $this->assertSame(1, $this->effectiveFor('beta_services'), 'pinned version kept');
        $this->assertSame(2, $this->effectiveFor('logistique'));
    }
}
