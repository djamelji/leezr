<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for GET /api/modules/quote endpoint (ADR-116).
 */
class ModuleQuoteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Quote Co',
            'slug' => 'quote-endpoint-co',
            'plan_key' => 'pro',
        ]);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Assign logistique jobdomain
        $jobdomain = Jobdomain::firstOrCreate(
            ['key' => 'logistique'],
            [
                'label' => 'Logistique',
                'is_active' => true,
                'default_modules' => [
                    'logistics_shipments',
                    'logistics_tracking',
                    'logistics_fleet',
                ],
                'allow_custom_fields' => true,
            ],
        );
        $this->company->jobdomains()->sync([$jobdomain->id]);
    }

    // ─── Auth ───────────────────────────────────────────────

    public function test_unauthorized_blocked(): void
    {
        $response = $this->getJson('/api/modules/quote?keys[]=logistics_shipments');

        $response->assertStatus(401);
    }

    public function test_missing_company_context_returns_400(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson('/api/modules/quote?keys[]=logistics_shipments');

        $response->assertStatus(400);
    }

    // ─── Valid response structure ───────────────────────────

    public function test_valid_quote_response_structure(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 15],
            ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote?keys[]=logistics_tracking');

        $response->assertOk()
            ->assertJsonStructure([
                'total',
                'currency',
                'lines' => [
                    ['key', 'title', 'amount', 'pricing_model'],
                ],
                'included' => [
                    ['key', 'title'],
                ],
            ]);
    }

    // ─── Requires listed in included ────────────────────────

    public function test_requires_listed_in_included(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote?keys[]=logistics_tracking');

        $response->assertOk();

        $included = $response->json('included');
        $includedKeys = array_column($included, 'key');

        $this->assertContains('logistics_shipments', $includedKeys);
    }

    // ─── Total correct ──────────────────────────────────────

    public function test_total_correct(): void
    {
        $this->company->update(['plan_key' => 'business']);

        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);
        PlatformModule::where('key', 'logistics_fleet')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 25],
            ]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote?keys[]=logistics_tracking&keys[]=logistics_fleet');

        $response->assertOk();

        $this->assertEquals(3500, $response->json('total'));
    }

    // ─── Deterministic ──────────────────────────────────────

    public function test_deterministic(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);

        $response1 = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote?keys[]=logistics_tracking');

        $response2 = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote?keys[]=logistics_tracking');

        $this->assertSame($response1->json(), $response2->json());
    }

    // ─── Disabled module rejected ───────────────────────────

    public function test_disabled_module_rejected(): void
    {
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['is_enabled_globally' => false]);

        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote?keys[]=logistics_shipments');

        $response->assertStatus(422)
            ->assertJsonPath('message', "Module 'logistics_shipments' is not available globally.");
    }

    // ─── Jobdomain mismatch rejected ────────────────────────

    public function test_jobdomain_mismatch_rejected(): void
    {
        // Create company without logistics jobdomain
        $user = User::factory()->create();
        $noJdCompany = Company::create([
            'name' => 'No JD Co',
            'slug' => 'no-jd-co',
            'plan_key' => 'pro',
        ]);
        $noJdCompany->memberships()->create([
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        // logistics_tracking requires logistique jobdomain
        $response = $this->actingAs($user)
            ->withHeader('X-Company-Id', $noJdCompany->id)
            ->getJson('/api/modules/quote?keys[]=logistics_tracking');

        $response->assertStatus(422);
    }

    // ─── Validation ─────────────────────────────────────────

    public function test_empty_keys_rejected(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote');

        $response->assertStatus(422);
    }

    public function test_non_existent_module_rejected(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/modules/quote?keys[]=nonexistent_module');

        $response->assertStatus(422);
    }
}
