<?php

namespace Tests\Feature;

use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainMarketOverlay;
use App\Core\Markets\Market;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobdomainOverlayApiTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;
    private Jobdomain $jobdomain;
    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);

        $this->jobdomain = Jobdomain::where('key', 'logistique')->first()
            ?? Jobdomain::create([
                'key' => 'logistique',
                'label' => 'Logistique',
                'is_active' => true,
                'default_modules' => ['core.members'],
                'default_fields' => [['code' => 'siret', 'order' => 0]],
            ]);

        $this->market = Market::firstOrCreate(
            ['key' => 'FR'],
            [
                'name' => 'France',
                'currency' => 'EUR',
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
                'dial_code' => '+33',
                'flag_code' => '🇫🇷',
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 1,
            ],
        );
    }

    // ─── Create Overlay ────────────────────────────────

    public function test_can_create_overlay(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays/{$this->market->key}", [
                'override_modules' => ['logistics.shipments'],
                'remove_fields' => ['siret'],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Overlay saved.')
            ->assertJsonPath('overlay.jobdomain_key', $this->jobdomain->key)
            ->assertJsonPath('overlay.market_key', $this->market->key);

        $this->assertDatabaseHas('jobdomain_market_overlays', [
            'jobdomain_key' => $this->jobdomain->key,
            'market_key' => $this->market->key,
        ]);
    }

    // ─── Update Overlay (idempotent upsert) ────────────

    public function test_can_update_existing_overlay(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => $this->jobdomain->key,
            'market_key' => $this->market->key,
            'override_modules' => ['logistics.shipments'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays/{$this->market->key}", [
                'override_modules' => ['logistics.shipments', 'logistics.deliveries'],
                'override_fields' => [['code' => 'nir', 'order' => 5]],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Overlay saved.');

        $overlay = JobdomainMarketOverlay::where('jobdomain_key', $this->jobdomain->key)
            ->where('market_key', $this->market->key)
            ->sole();

        $this->assertEquals(['logistics.shipments', 'logistics.deliveries'], $overlay->override_modules);
        $this->assertEquals([['code' => 'nir', 'order' => 5]], $overlay->override_fields);
        $this->assertCount(1, JobdomainMarketOverlay::where('jobdomain_key', $this->jobdomain->key)->get());
    }

    // ─── Delete Overlay ────────────────────────────────

    public function test_can_delete_overlay(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => $this->jobdomain->key,
            'market_key' => $this->market->key,
            'override_modules' => ['logistics.shipments'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays/{$this->market->key}");

        $response->assertOk()
            ->assertJsonPath('message', 'Overlay deleted.');

        $this->assertDatabaseMissing('jobdomain_market_overlays', [
            'jobdomain_key' => $this->jobdomain->key,
            'market_key' => $this->market->key,
        ]);
    }

    public function test_delete_nonexistent_overlay_returns_404(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays/{$this->market->key}");

        $response->assertNotFound();
    }

    // ─── Validation ────────────────────────────────────

    public function test_upsert_rejects_invalid_field_structure(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays/{$this->market->key}", [
                'override_fields' => [['missing_code_key' => true]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['override_fields.0.code']);
    }

    public function test_upsert_rejects_invalid_document_structure(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays/{$this->market->key}", [
                'override_documents' => [['missing_code_key' => true]],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['override_documents.0.code']);
    }

    public function test_upsert_with_nonexistent_jobdomain_returns_404(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/nonexistent_jd/overlays/{$this->market->key}", [
                'override_modules' => ['core.members'],
            ]);

        $response->assertNotFound();
    }

    public function test_upsert_with_nonexistent_market_returns_404(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays/XX", [
                'override_modules' => ['core.members'],
            ]);

        $response->assertNotFound();
    }

    // ─── List Overlays ─────────────────────────────────

    public function test_can_list_overlays_for_jobdomain(): void
    {
        $marketGB = Market::firstOrCreate(
            ['key' => 'GB'],
            [
                'name' => 'United Kingdom',
                'currency' => 'GBP',
                'locale' => 'en_GB',
                'timezone' => 'Europe/London',
                'dial_code' => '+44',
                'flag_code' => '🇬🇧',
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 2,
            ],
        );

        JobdomainMarketOverlay::create([
            'jobdomain_key' => $this->jobdomain->key,
            'market_key' => $this->market->key,
            'override_modules' => ['logistics.shipments'],
        ]);
        JobdomainMarketOverlay::create([
            'jobdomain_key' => $this->jobdomain->key,
            'market_key' => $marketGB->key,
            'remove_fields' => ['siret'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/jobdomains/{$this->jobdomain->key}/overlays");

        $response->assertOk()
            ->assertJsonStructure(['overlays' => ['FR', 'GB']]);
    }

    // ─── Resolved Preview via Detail ───────────────────

    public function test_detail_includes_resolved_previews(): void
    {
        JobdomainMarketOverlay::create([
            'jobdomain_key' => $this->jobdomain->key,
            'market_key' => $this->market->key,
            'override_modules' => ['logistics.shipments'],
            'remove_fields' => ['siret'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/jobdomains/{$this->jobdomain->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'overlays',
                'markets',
                'resolved_previews' => ['_global', 'FR'],
            ]);

        $previews = $response->json('resolved_previews');

        // Global must have base modules from jobdomain defaults
        $this->assertArrayHasKey('modules', $previews['_global']);
        $this->assertArrayHasKey('fields', $previews['_global']);
        $this->assertArrayHasKey('documents', $previews['_global']);
        $this->assertArrayHasKey('roles', $previews['_global']);

        // FR overlay: logistics.shipments added, siret removed
        $frModules = $previews['FR']['modules'];
        $frFieldCodes = collect($previews['FR']['fields'])->pluck('code')->toArray();

        $this->assertContains('logistics.shipments', $frModules);
        $this->assertNotContains('siret', $frFieldCodes);
    }

    public function test_detail_without_overlays_only_has_global_preview(): void
    {
        $jd = Jobdomain::create([
            'key' => 'test_no_overlay',
            'label' => 'Test No Overlay',
            'is_active' => true,
            'default_modules' => ['core.members'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson("/api/platform/jobdomains/{$jd->id}");

        $response->assertOk();

        $previews = $response->json('resolved_previews');
        $this->assertArrayHasKey('_global', $previews);
        $this->assertCount(1, $previews);
    }
}
