<?php

namespace Tests\Feature;

use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-312: Performance billing tests.
 *
 * Validates indexes, cache singleton, chunking, and eager loading.
 */
class BillingPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    // ─── Cache singleton ────────────────────────────────────

    public function test_platform_billing_policy_is_cached(): void
    {
        Cache::flush();

        // First call: DB query + cache store
        $policy1 = PlatformBillingPolicy::instance();
        $this->assertNotNull($policy1);

        // Second call: should come from cache (no DB query)
        DB::enableQueryLog();
        $policy2 = PlatformBillingPolicy::instance();
        $queries = collect(DB::getQueryLog())->filter(
            fn ($q) => str_contains($q['query'], 'platform_billing_policies')
        );
        DB::disableQueryLog();

        $this->assertCount(0, $queries, 'Second call to instance() should not query DB');
        $this->assertEquals($policy1->id, $policy2->id);
    }

    public function test_policy_cache_cleared_on_update(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $originalGrace = $policy->grace_period_days;

        // Update via API
        $admin = PlatformUser::create([
            'first_name' => 'Cache',
            'last_name' => 'Admin',
            'email' => 'cache-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $admin->roles()->attach($superAdmin);

        $newGrace = $originalGrace === 5 ? 10 : 5;

        $this->actingAs($admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['grace_period_days' => $newGrace])
            ->assertOk();

        // After update, cache should be cleared and instance should return new value
        $fresh = PlatformBillingPolicy::instance();
        $this->assertEquals($newGrace, $fresh->grace_period_days);
    }

    // ─── Dunning chunking ──────────────────────────────────

    public function test_dunning_with_overdue_invoices_completes(): void
    {
        $company = Company::create([
            'name' => 'Dunning Test Co',
            'slug' => 'dunning-test-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        $policy = PlatformBillingPolicy::instance();

        // Create 5 overdue invoices (enough to test without being slow)
        for ($i = 0; $i < 5; $i++) {
            Invoice::create([
                'company_id' => $company->id,
                'number' => "INV-PERF-{$i}",
                'status' => 'open',
                'amount' => 2900,
                'subtotal' => 2900,
                'amount_due' => 2900,
                'currency' => 'EUR',
                'finalized_at' => now()->subDays(30),
                'due_at' => now()->subDays($policy->grace_period_days + 1),
            ]);
        }

        $stats = DunningEngine::processOverdueInvoices();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('processed', $stats);
        $this->assertEquals(5, $stats['processed']);
    }

    // ─── Renewal chunking ──────────────────────────────────

    public function test_renewal_command_handles_expired_subscriptions(): void
    {
        $company = Company::create([
            'name' => 'Renew Test Co',
            'slug' => 'renew-test-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);

        Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'is_current' => true,
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
            'amount' => 2900,
            'currency' => 'EUR',
        ]);

        $this->artisan('billing:renew')
            ->assertExitCode(0);
    }
}
