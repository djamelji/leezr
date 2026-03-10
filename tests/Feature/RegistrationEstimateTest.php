<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Markets\Market;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-324: Registration estimate endpoint tests.
 *
 * POST /api/public/plans/estimate-registration
 */
class RegistrationEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        Market::firstOrCreate(
            ['key' => 'FR'],
            ['name' => 'France', 'currency' => 'EUR', 'vat_rate_bps' => 2000, 'locale' => 'fr_FR', 'timezone' => 'Europe/Paris', 'dial_code' => '+33'],
        );
    }

    public function test_estimate_registration_returns_breakdown(): void
    {
        $response = $this->postJson('/api/public/plans/estimate-registration', [
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'market_key' => 'FR',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'lines',
            'subtotal',
            'tax_rate_bps',
            'tax_amount',
            'total',
            'currency',
        ]);

        $data = $response->json();
        $this->assertIsArray($data['lines']);
        $this->assertGreaterThanOrEqual(0, $data['subtotal']);
        $this->assertEquals($data['subtotal'] + $data['tax_amount'], $data['total']);
    }

    public function test_estimate_registration_with_coupon(): void
    {
        $coupon = BillingCoupon::create([
            'code' => 'REGTEST15',
            'name' => 'RegTest 15%',
            'type' => 'percentage',
            'value' => 1500, // 15%
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/public/plans/estimate-registration', [
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'market_key' => 'FR',
            'coupon_code' => 'REGTEST15',
        ]);

        $response->assertOk();
        $data = $response->json();

        $this->assertNotNull($data['coupon']);
        $this->assertEquals('REGTEST15', $data['coupon']['code']);

        // Should have a discount line
        $discountLines = array_filter($data['lines'], fn ($l) => $l['type'] === 'discount');
        $this->assertNotEmpty($discountLines);
    }

    public function test_estimate_registration_with_tax(): void
    {
        $market = Market::where('key', 'FR')->first();
        $vatBps = $market?->vat_rate_bps ?? 0;

        $response = $this->postJson('/api/public/plans/estimate-registration', [
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'market_key' => 'FR',
        ]);

        $response->assertOk();
        $data = $response->json();

        if ($vatBps > 0 && $data['subtotal'] > 0) {
            $this->assertGreaterThan(0, $data['tax_amount']);
            $expectedTax = (int) floor($data['subtotal'] * $vatBps / 10000);
            $this->assertEquals($expectedTax, $data['tax_amount']);
        }
    }

    public function test_estimate_registration_invalid_plan_returns_error(): void
    {
        $response = $this->postJson('/api/public/plans/estimate-registration', [
            'plan_key' => 'nonexistent_plan',
            'interval' => 'monthly',
            'market_key' => 'FR',
        ]);

        $response->assertStatus(500); // RuntimeException from PricingEngine
    }

    public function test_estimate_registration_validation(): void
    {
        $response = $this->postJson('/api/public/plans/estimate-registration', []);
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['plan_key', 'interval', 'market_key']);
    }
}
