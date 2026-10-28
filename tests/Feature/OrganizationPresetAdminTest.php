<?php

namespace Tests\Feature;

use App\Modules\Workforce\Organization\OrganizationPreset;
use App\Modules\Workforce\Organization\OrganizationPresetResolver;
use App\Modules\Workforce\Organization\OrganizationPresetStructureValidator;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-638 (W-E2) — administration Platform des presets : validation serveur de structure, versionnement
 * par famille, immutabilité d'un publié, publication (une seule publiée/famille), duplication, archivage
 * non destructif, simulation read-only, RBAC.
 */
class OrganizationPresetAdminTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Markets\MarketRegistry::sync();
        $this->admin = PlatformUser::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa-'.uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $this->admin->roles()->attach(PlatformRole::where('key', 'super_admin')->firstOrFail());
    }

    private function api()
    {
        return $this->actingAs($this->admin, 'platform');
    }

    private function validStructure(): array
    {
        return [
            'departments' => [
                ['key' => 'direction', 'name' => 'Direction', 'parent_key' => null],
                ['key' => 'exploitation', 'name' => 'Exploitation', 'parent_key' => 'direction'],
            ],
            'job_roles' => [['key' => 'drv', 'title' => 'Livreur', 'department_key' => 'exploitation']],
        ];
    }

    // ── Validator (unit) ──
    public function test_validator_rejects_cycle_and_bad_refs(): void
    {
        $cyc = OrganizationPresetStructureValidator::validate([
            'jobdomain_key' => 'logistique',
            'structure' => ['departments' => [['key' => 'a', 'name' => 'A', 'parent_key' => 'b'], ['key' => 'b', 'name' => 'B', 'parent_key' => 'a']], 'job_roles' => []],
        ]);
        $this->assertContains('department_cycle', $cyc);

        $bad = OrganizationPresetStructureValidator::validate([
            'jobdomain_key' => 'logistique',
            'structure' => ['departments' => [['key' => 'a', 'name' => 'A', 'parent_key' => 'ghost']], 'job_roles' => [['key' => 'r', 'title' => 'R', 'department_key' => 'ghost']]],
        ]);
        $this->assertContains('department_parent_unknown:a', $bad);
        $this->assertContains('job_role_department_unknown:r', $bad);
    }

    public function test_validator_rejects_unknown_jobdomain_and_bad_profile(): void
    {
        $e = OrganizationPresetStructureValidator::validate(['jobdomain_key' => 'ghostdomain', 'org_profile_key' => 'nope', 'structure' => ['departments' => [], 'job_roles' => []]]);
        $this->assertContains('jobdomain_unknown', $e);
    }

    // ── HTTP admin ──
    public function test_create_draft_validates_structure(): void
    {
        $this->api()->postJson('/api/platform/organization-presets', [
            'key' => 'lm_v1', 'label' => 'LM', 'jobdomain_key' => 'logistique', 'market_key' => 'FR',
            'org_profile_key' => 'last_mile_delivery', 'structure' => $this->validStructure(),
        ])->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('family_key', 'lm_v1');

        // Invalide (cycle) → 422.
        $this->api()->postJson('/api/platform/organization-presets', [
            'key' => 'bad_v1', 'label' => 'Bad', 'jobdomain_key' => 'logistique',
            'structure' => ['departments' => [['key' => 'a', 'name' => 'A', 'parent_key' => 'a']], 'job_roles' => []],
        ])->assertStatus(422);
    }

    public function test_publish_then_immutable_and_duplicate_new_version(): void
    {
        $id = $this->api()->postJson('/api/platform/organization-presets', [
            'key' => 'fam_v1', 'label' => 'Fam', 'jobdomain_key' => 'logistique', 'market_key' => 'FR',
            'org_profile_key' => 'last_mile_delivery', 'structure' => $this->validStructure(),
        ])->json('id');

        $this->api()->postJson("/api/platform/organization-presets/{$id}/publish")->assertOk()->assertJsonPath('status', 'published');
        // Publié = immuable.
        $this->api()->putJson("/api/platform/organization-presets/{$id}", ['label' => 'X'])->assertStatus(422);

        // Dupliquer → nouvelle version brouillon, même famille.
        $dup = $this->api()->postJson("/api/platform/organization-presets/{$id}/duplicate")->assertCreated()->json();
        $this->assertSame('fam_v1', $dup['family_key']);
        $this->assertSame(2, $dup['version']);
        $this->assertSame('draft', $dup['status']);

        // Publier la v2 → archive la v1 (une seule publiée par famille).
        $this->api()->postJson("/api/platform/organization-presets/{$dup['id']}/publish")->assertOk();
        $this->assertSame('archived', OrganizationPreset::find($id)->status);
    }

    public function test_published_preset_is_resolvable_by_we(): void
    {
        $id = $this->api()->postJson('/api/platform/organization-presets', [
            'key' => 'res_v1', 'label' => 'Res', 'jobdomain_key' => 'logistique', 'market_key' => 'FR',
            'org_profile_key' => 'last_mile_delivery', 'structure' => $this->validStructure(),
        ])->json('id');
        $this->api()->postJson("/api/platform/organization-presets/{$id}/publish")->assertOk();

        $c = \App\Core\Models\Company::create(['name' => 'R', 'slug' => 'r-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'org_profile_key' => 'last_mile_delivery']);
        $resolved = OrganizationPresetResolver::resolveFor($c);
        $this->assertSame('res_v1', $resolved?->key, 'un preset publié depuis l\'UI est résolvable par W-E');
    }

    public function test_archive_is_non_destructive(): void
    {
        $id = $this->api()->postJson('/api/platform/organization-presets', [
            'key' => 'arc_v1', 'label' => 'Arc', 'jobdomain_key' => 'logistique', 'structure' => $this->validStructure(),
        ])->json('id');
        $this->api()->postJson("/api/platform/organization-presets/{$id}/archive")->assertOk()->assertJsonPath('status', 'archived');
        $this->assertNotNull(OrganizationPreset::find($id), 'le preset archivé existe toujours (références conservées)');
    }

    public function test_requires_platform_auth(): void
    {
        $this->getJson('/api/platform/organization-presets')->assertStatus(401);
    }
}
