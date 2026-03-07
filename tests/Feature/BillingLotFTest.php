<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Payment;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModuleActivationReason;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-227: Billing Observability & Operations (LOT F).
 *
 * Tests: billing:health-check, platform metrics endpoint.
 */
class BillingLotFTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;

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

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'LotF Co',
            'slug' => 'lotf-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    // ── F1: billing:health-check ─────────────────────────

    public function test_health_check_detects_orphan_addon(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        // Active addon subscription but no activation reason (module not enabled)
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(5),
        ]);

        $this->artisan('billing:health-check')
            ->assertExitCode(1);
    }

    public function test_health_check_detects_missing_invoice(): void
    {
        // Active paid subscription with no invoice for current period
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'stripe',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $this->artisan('billing:health-check')
            ->assertExitCode(1);
    }

    public function test_health_check_returns_healthy_when_no_issues(): void
    {
        // Free plan subscription — no invoice needed
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $this->artisan('billing:health-check')
            ->assertExitCode(0);
    }

    // ── F3: Platform Metrics endpoint ────────────────────

    public function test_metrics_endpoint_returns_mrr_arr(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        $admin = PlatformUser::create([
            'first_name' => 'Metrics',
            'last_name' => 'Admin',
            'email' => 'metrics-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $response = $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/billing/metrics');

        $response->assertOk();
        $response->assertJsonStructure([
            'mrr',
            'arr',
            'active_subscriptions',
            'trialing_subscriptions',
            'addon_mrr',
            'churn_rate',
        ]);
        $this->assertGreaterThan(0, $response->json('mrr'));
        $this->assertEquals($response->json('mrr') * 12, $response->json('arr'));
    }

    public function test_metrics_include_addon_revenue(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(5),
        ]);

        $admin = PlatformUser::create([
            'first_name' => 'Addon',
            'last_name' => 'Admin',
            'email' => 'addon-metrics@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $response = $this->actingAs($admin, 'platform')
            ->getJson('/api/platform/billing/metrics');

        $response->assertOk();
        $this->assertEquals(2900, $response->json('addon_mrr'));
    }
}
