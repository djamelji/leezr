<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformMarketsCrudTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@markets-test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ─── INDEX ────────────────────────────────────────────

    public function test_can_list_markets(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/markets');

        // MarketRegistry::sync() seeds FR + GB = 2 markets
        $response->assertOk()
            ->assertJsonCount(2);
    }

    public function test_list_markets_includes_companies_count_and_languages(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/markets');

        $response->assertOk();

        $markets = $response->json();
        foreach ($markets as $market) {
            $this->assertArrayHasKey('companies_count', $market);
            $this->assertArrayHasKey('languages', $market);
        }
    }

    public function test_list_markets_ordered_by_sort_order(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/markets');

        $response->assertOk();

        $sortOrders = collect($response->json())->pluck('sort_order')->toArray();
        $sorted = $sortOrders;
        sort($sorted);
        $this->assertEquals($sorted, $sortOrders);
    }

    // ─── SHOW ─────────────────────────────────────────────

    public function test_can_show_single_market(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/markets/FR');

        $response->assertOk()
            ->assertJsonStructure([
                'market' => ['id', 'key', 'name', 'currency', 'locale', 'timezone', 'legal_statuses', 'languages'],
                'companies',
            ])
            ->assertJsonPath('market.key', 'FR');
    }

    public function test_show_unknown_market_returns_404(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/markets/ZZ');

        $response->assertNotFound();
    }

    // ─── STORE ────────────────────────────────────────────

    public function test_can_create_market(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/markets', [
                'key' => 'DE',
                'name' => 'Germany',
                'currency' => 'EUR',
                'locale' => 'de-DE',
                'timezone' => 'Europe/Berlin',
                'dial_code' => '+49',
                'flag_code' => 'DE',
                'is_active' => true,
                'sort_order' => 2,
            ]);

        $response->assertCreated()
            ->assertJsonPath('market.key', 'DE')
            ->assertJsonPath('market.name', 'Germany');

        $this->assertDatabaseHas('markets', ['key' => 'DE', 'name' => 'Germany']);
    }

    public function test_store_validation_rejects_missing_required_fields(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/markets', [
                // Missing all required fields
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key', 'name', 'currency', 'locale', 'timezone', 'dial_code']);
    }

    public function test_store_validation_rejects_duplicate_key(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/markets', [
                'key' => 'FR', // Already exists from seed
                'name' => 'France Duplicate',
                'currency' => 'EUR',
                'locale' => 'fr-FR',
                'timezone' => 'Europe/Paris',
                'dial_code' => '+33',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    }

    public function test_store_validation_rejects_invalid_key_format(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/markets', [
                'key' => 'fr', // Must be uppercase
                'name' => 'France lowercase',
                'currency' => 'EUR',
                'locale' => 'fr-FR',
                'timezone' => 'Europe/Paris',
                'dial_code' => '+33',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    }

    // ─── UPDATE ───────────────────────────────────────────

    public function test_can_update_market(): void
    {
        $market = Market::where('key', 'FR')->first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/markets/{$market->id}", [
                'key' => 'FR',
                'name' => 'France Updated',
                'currency' => 'EUR',
                'locale' => 'fr-FR',
                'timezone' => 'Europe/Paris',
                'dial_code' => '+33',
                'flag_code' => 'FR',
                'sort_order' => 0,
            ]);

        $response->assertOk()
            ->assertJsonPath('market.name', 'France Updated');

        $this->assertDatabaseHas('markets', ['key' => 'FR', 'name' => 'France Updated']);
    }

    // ─── TOGGLE ACTIVE ────────────────────────────────────

    public function test_can_toggle_market_active(): void
    {
        // Create a new active market with no companies
        $market = Market::create([
            'key' => 'CH',
            'name' => 'Switzerland',
            'currency' => 'CHF',
            'locale' => 'de-CH',
            'timezone' => 'Europe/Zurich',
            'dial_code' => '+41',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/markets/{$market->id}/toggle-active");

        $response->assertOk()
            ->assertJsonPath('market.is_active', false);
    }

    public function test_cannot_deactivate_market_with_companies(): void
    {
        $market = Market::where('key', 'FR')->first();

        // Create a company attached to this market
        Company::create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'market_key' => 'FR',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/markets/{$market->id}/toggle-active");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['market']);
    }

    // ─── SET DEFAULT ──────────────────────────────────────

    public function test_can_set_default_market(): void
    {
        $gb = Market::where('key', 'GB')->first();

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/markets/{$gb->id}/set-default");

        $response->assertOk()
            ->assertJsonPath('market.is_default', true);

        // FR should no longer be default
        $this->assertFalse(Market::where('key', 'FR')->first()->is_default);
    }

    public function test_cannot_set_inactive_market_as_default(): void
    {
        $market = Market::create([
            'key' => 'IT',
            'name' => 'Italy',
            'currency' => 'EUR',
            'locale' => 'it-IT',
            'timezone' => 'Europe/Rome',
            'dial_code' => '+39',
            'is_active' => false,
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/markets/{$market->id}/set-default");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['market']);
    }

    // ─── EXPORT ───────────────────────────────────────────

    public function test_can_export_markets(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/markets/export');

        $response->assertOk()
            ->assertJsonStructure(['_meta', 'FR', 'GB']);

        $fr = $response->json('FR');
        $this->assertEquals('France', $fr['name']);
        $this->assertArrayHasKey('legal_statuses', $fr);
        $this->assertArrayHasKey('languages', $fr);
    }
}
