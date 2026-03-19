<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Markets\TranslationBundle;
use App\Core\Markets\TranslationOverride;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class PlatformTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@translations-test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    // ─── BUNDLE INDEX ─────────────────────────────────────

    public function test_can_list_translation_bundles(): void
    {
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Hello', 'farewell' => 'Goodbye'],
        ]);

        TranslationBundle::create([
            'locale' => 'fr',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Bonjour', 'farewell' => 'Au revoir'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations');

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');

        // Verify keys_count is appended
        $first = $response->json('data.0');
        $this->assertArrayHasKey('keys_count', $first);
        $this->assertEquals(2, $first['keys_count']);
    }

    public function test_can_filter_bundles_by_locale(): void
    {
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['hello' => 'Hello'],
        ]);

        TranslationBundle::create([
            'locale' => 'fr',
            'namespace' => 'common',
            'translations' => ['hello' => 'Bonjour'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations?locale=fr');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.locale', 'fr');
    }

    public function test_can_filter_bundles_by_namespace(): void
    {
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['hello' => 'Hello'],
        ]);

        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'billing',
            'translations' => ['invoice' => 'Invoice'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations?namespace=billing');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.namespace', 'billing');
    }

    // ─── BUNDLE SHOW ──────────────────────────────────────

    public function test_can_show_single_bundle(): void
    {
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Hello', 'farewell' => 'Goodbye'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations/en/common');

        $response->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('namespace', 'common')
            ->assertJsonPath('translations.greeting', 'Hello');
    }

    public function test_show_bundle_returns_404_when_not_found(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations/xx/nonexistent');

        $response->assertNotFound();
    }

    // ─── BUNDLE UPDATE ────────────────────────────────────

    public function test_can_update_bundle_translations(): void
    {
        $bundle = TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Hello'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/translations/{$bundle->id}", [
                'translations' => ['greeting' => 'Hi', 'farewell' => 'Bye'],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Translations updated.')
            ->assertJsonPath('bundle.translations.greeting', 'Hi')
            ->assertJsonPath('bundle.translations.farewell', 'Bye');

        $this->assertDatabaseHas('translation_bundles', ['id' => $bundle->id]);
        $bundle->refresh();
        $this->assertEquals('Hi', $bundle->translations['greeting']);
    }

    public function test_update_bundle_requires_translations_array(): void
    {
        $bundle = TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Hello'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson("/api/platform/translations/{$bundle->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['translations']);
    }

    // ─── EXPORT ───────────────────────────────────────────

    public function test_can_export_locale_bundles(): void
    {
        TranslationBundle::create([
            'locale' => 'fr',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Bonjour'],
        ]);

        TranslationBundle::create([
            'locale' => 'fr',
            'namespace' => 'billing',
            'translations' => ['invoice' => 'Facture'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations/export/fr');

        $response->assertOk()
            ->assertJsonPath('common.greeting', 'Bonjour')
            ->assertJsonPath('billing.invoice', 'Facture');
    }

    // ─── MATRIX ───────────────────────────────────────────

    public function test_can_get_translation_matrix(): void
    {
        // Use a unique namespace that won't conflict with static JSON keys
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'testns',
            'translations' => ['greeting' => 'Hello', 'farewell' => 'Goodbye'],
        ]);

        TranslationBundle::create([
            'locale' => 'fr',
            'namespace' => 'testns',
            'translations' => ['greeting' => 'Bonjour'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations/matrix?section=testns&locales=en,fr&per_page=50');

        $response->assertOk()
            ->assertJsonPath('section', 'testns')
            ->assertJsonStructure([
                'section',
                'locales',
                'rows' => [['key', 'values']],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        // Should have 2 unique keys (greeting + farewell) since "testns" has no static JSON
        $this->assertEquals(2, $response->json('pagination.total'));
    }

    public function test_matrix_supports_search_filter(): void
    {
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Hello', 'farewell' => 'Goodbye', 'thanks' => 'Thank you'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations/matrix?section=common&locales=en&q=greet');

        $response->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('rows.0.key', 'greeting');
    }

    public function test_can_update_matrix(): void
    {
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['greeting' => 'Hello'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/translations/matrix', [
                'section' => 'common',
                'locales' => ['en'],
                'rows' => [
                    ['key' => 'greeting', 'values' => ['en' => 'Hi there']],
                    ['key' => 'farewell', 'values' => ['en' => 'See ya']],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('updated_count', 2);

        $bundle = TranslationBundle::where('locale', 'en')
            ->where('namespace', 'common')
            ->first();

        $this->assertEquals('Hi there', $bundle->translations['greeting']);
        $this->assertEquals('See ya', $bundle->translations['farewell']);
    }

    public function test_matrix_stats_returns_locale_summary(): void
    {
        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'common',
            'translations' => ['a' => '1', 'b' => '2'],
        ]);

        TranslationBundle::create([
            'locale' => 'en',
            'namespace' => 'billing',
            'translations' => ['c' => '3'],
        ]);

        TranslationBundle::create([
            'locale' => 'fr',
            'namespace' => 'common',
            'translations' => ['a' => '1'],
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations/stats');

        $response->assertOk()
            ->assertJsonStructure(['locales' => ['en' => ['bundles', 'keys'], 'fr' => ['bundles', 'keys']]])
            ->assertJsonPath('locales.en.bundles', 2)
            ->assertJsonPath('locales.en.keys', 3)
            ->assertJsonPath('locales.fr.bundles', 1)
            ->assertJsonPath('locales.fr.keys', 1);
    }

    // ─── OVERRIDES ────────────────────────────────────────

    public function test_can_list_market_overrides(): void
    {
        $market = Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'dial_code' => '+33',
            'is_active' => true,
        ]);

        TranslationOverride::create([
            'market_key' => 'FR',
            'locale' => 'fr',
            'namespace' => 'common',
            'key' => 'greeting',
            'value' => 'Salut',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/translations/overrides/FR/fr');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.key', 'greeting')
            ->assertJsonPath('0.value', 'Salut');
    }

    public function test_can_upsert_market_overrides(): void
    {
        Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'dial_code' => '+33',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/translations/overrides/FR', [
                'locale' => 'fr',
                'overrides' => [
                    ['namespace' => 'common', 'key' => 'greeting', 'value' => 'Salut'],
                    ['namespace' => 'common', 'key' => 'farewell', 'value' => 'Ciao'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('count', 2);

        $this->assertDatabaseCount('translation_overrides', 2);

        // Upsert same key with new value
        $response2 = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/translations/overrides/FR', [
                'locale' => 'fr',
                'overrides' => [
                    ['namespace' => 'common', 'key' => 'greeting', 'value' => 'Bonjour'],
                ],
            ]);

        $response2->assertOk();

        // Should still be 2 (upserted, not duplicated)
        $this->assertDatabaseCount('translation_overrides', 2);
        $this->assertDatabaseHas('translation_overrides', [
            'market_key' => 'FR',
            'key' => 'greeting',
            'value' => 'Bonjour',
        ]);
    }

    public function test_can_delete_market_override(): void
    {
        Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'dial_code' => '+33',
            'is_active' => true,
        ]);

        $override = TranslationOverride::create([
            'market_key' => 'FR',
            'locale' => 'fr',
            'namespace' => 'common',
            'key' => 'greeting',
            'value' => 'Salut',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->deleteJson("/api/platform/translations/overrides/{$override->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Override removed.');

        $this->assertDatabaseMissing('translation_overrides', ['id' => $override->id]);
    }

    public function test_override_upsert_validates_required_fields(): void
    {
        Market::create([
            'key' => 'FR',
            'name' => 'France',
            'currency' => 'EUR',
            'locale' => 'fr',
            'timezone' => 'Europe/Paris',
            'dial_code' => '+33',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->putJson('/api/platform/translations/overrides/FR', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['locale', 'overrides']);
    }
}
