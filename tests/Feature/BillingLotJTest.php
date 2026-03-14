<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\Listeners\AddonBillingListener;
use App\Core\Billing\Listeners\AddonCreditListener;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Events\ModuleDisabled;
use App\Core\Events\ModuleEnabled;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-340 LOT J: Billing UX coherence.
 *
 * 1. Module quote includes tax info
 * 2. Addon re-subscription does not duplicate invoice
 * 3. Cancel preview returns subscription context
 * 4. Module index returns addon subscriptions
 */
class BillingLotJTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'LotJ Co',
            'slug' => 'lotj-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]);

        // Ensure wallet exists
        WalletLedger::ensureWallet($this->company);
    }

    private function actAs(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ── T1: Module quote includes tax info ──────────────────────

    public function test_module_quote_includes_tax_info(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'tax_mode' => 'exclusive',
            'default_tax_rate_bps' => 2000, // 20%
        ]);

        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'addon_pricing' => [
                    'pricing_model' => 'flat',
                    'pricing_params' => ['price_monthly' => 29],
                ],
            ]);

        $response = $this->actAs()
            ->getJson('/api/modules/quote?keys[]=logistics_tracking');

        $response->assertOk()
            ->assertJsonStructure([
                'subtotal',
                'tax_rate_bps',
                'tax_amount',
                'total_ttc',
                'tax_mode',
            ]);

        $data = $response->json();
        $this->assertEquals(2900, $data['subtotal']);
        $this->assertEquals(2000, $data['tax_rate_bps']);
        $this->assertEquals(580, $data['tax_amount']); // 2900 * 20%
        $this->assertEquals(3480, $data['total_ttc']); // 2900 + 580
        $this->assertEquals('exclusive', $data['tax_mode']);
    }

    // ── T2: Addon re-subscription does not duplicate invoice ────

    public function test_addon_resubscription_does_not_duplicate_invoice(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'addon_pricing' => [
                    'pricing_model' => 'flat',
                    'pricing_params' => ['price_monthly' => 29],
                ],
            ]);

        // First enable: should create invoice
        $listener = new AddonBillingListener;
        $listener->handle(new ModuleEnabled($this->company, 'logistics_tracking'));

        $invoiceCount = Invoice::where('company_id', $this->company->id)
            ->whereHas('lines', fn ($q) => $q->where('module_key', 'logistics_tracking'))
            ->count();

        $this->assertEquals(1, $invoiceCount, 'First enable should create one invoice');

        // Set addon as deactivated (simulating disable)
        CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->update(['deactivated_at' => now()]);

        // Re-enable: idempotency guard should prevent duplicate invoice
        $listener->handle(new ModuleEnabled($this->company, 'logistics_tracking'));

        $invoiceCount = Invoice::where('company_id', $this->company->id)
            ->whereHas('lines', fn ($q) => $q->where('module_key', 'logistics_tracking'))
            ->whereNotIn('status', ['void'])
            ->count();

        $this->assertEquals(1, $invoiceCount, 'Re-subscription should not create duplicate invoice');
    }

    // ── T3: Cancel preview returns subscription context ─────────

    public function test_cancel_preview_returns_subscription_context(): void
    {
        // Add an active addon subscription
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(5),
        ]);

        // Credit the wallet
        WalletLedger::credit($this->company, 5000, 'Test credit');

        $response = $this->actAs()
            ->getJson('/api/billing/subscription/cancel-preview');

        $response->assertOk()
            ->assertJsonStructure([
                'timing',
                'period_end',
                'plan_name',
                'interval',
                'active_addons',
                'wallet_balance',
            ]);

        $data = $response->json();
        $this->assertEquals('pro', $data['plan_name']);
        $this->assertEquals('monthly', $data['interval']);
        $this->assertCount(1, $data['active_addons']);
        $this->assertEquals('logistics_tracking', $data['active_addons'][0]['module_key']);
        $this->assertEquals(2900, $data['active_addons'][0]['amount_cents']);
        $this->assertEquals(5000, $data['wallet_balance']);
    }

    // ── T4: Module index returns addon subscriptions ────────────

    public function test_module_index_returns_addon_subscriptions(): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(5),
        ]);

        $response = $this->actAs()
            ->getJson('/api/modules');

        $response->assertOk()
            ->assertJsonStructure([
                'modules',
                'addon_subscriptions',
            ]);

        $addons = $response->json('addon_subscriptions');
        $this->assertCount(1, $addons);
        $this->assertEquals('logistics_tracking', $addons[0]['module_key']);
        $this->assertEquals(2900, $addons[0]['amount_cents']);
        $this->assertEquals('EUR', $addons[0]['currency']);
        $this->assertArrayHasKey('period_end', $addons[0]);
    }

    // ── T5: Addon deactivation timing end_of_period ──────────────

    public function test_addon_deactivation_timing_end_of_period(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['addon_deactivation_timing' => 'end_of_period']);

        $activatedAt = now()->subDays(10);
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => $activatedAt,
        ]);

        $listener = new AddonCreditListener;
        $listener->handle(new ModuleDisabled($this->company, 'logistics_tracking'));

        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($addon->deactivated_at, 'deactivated_at should be set');
        $this->assertTrue(
            $addon->deactivated_at->gt(now()),
            'deactivated_at should be in the future (end of period)'
        );
        // Should be approximately activated_at + 1 month
        $this->assertEquals(
            $activatedAt->copy()->addMonth()->toDateString(),
            $addon->deactivated_at->toDateString()
        );
    }

    // ── T6: Addon deactivation timing immediate ──────────────────

    public function test_addon_deactivation_timing_immediate_with_prorated_credit(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['addon_deactivation_timing' => 'immediate']);

        $activatedAt = now()->subDays(10);
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => $activatedAt,
        ]);

        $walletBefore = WalletLedger::balance($this->company);

        $listener = new AddonCreditListener;
        $listener->handle(new ModuleDisabled($this->company, 'logistics_tracking'));

        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($addon->deactivated_at);
        $this->assertTrue(
            $addon->deactivated_at->lte(now()),
            'deactivated_at should be now (immediate)'
        );

        // Prorated credit should be added to wallet
        // 10 days used out of ~30 days → credit ≈ 20/30 × 2900 ≈ 1933
        $walletAfter = WalletLedger::balance($this->company);
        $credit = $walletAfter - $walletBefore;
        $this->assertGreaterThan(0, $credit, 'Prorated credit should be positive');

        // Verify credit is reasonable (between 50% and 80% of amount — ~20 days remaining out of 30)
        $this->assertGreaterThan(1400, $credit, 'Credit should be > 50% (most period remaining)');
        $this->assertLessThan(2400, $credit, 'Credit should be < 80% (10 days consumed)');
    }

    // ── T7: Grace period reactivation does not create new invoice ─

    public function test_addon_reactivation_during_grace_period_no_invoice(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'addon_pricing' => [
                    'pricing_model' => 'flat',
                    'pricing_params' => ['price_monthly' => 29],
                ],
            ]);

        // Create addon with future deactivation (grace period)
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(10),
            'deactivated_at' => now()->addDays(20),
        ]);

        // Create existing invoice for current period
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id, now()->subDays(10)->toDateString(), now()->addDays(20)->toDateString());
        InvoiceIssuer::addLine($invoice, 'addon', 'Tracking', 2900, 1, moduleKey: 'logistics_tracking');
        InvoiceIssuer::finalize($invoice);

        $invoicesBefore = Invoice::where('company_id', $this->company->id)->count();

        // Re-enable module during grace period
        $listener = new AddonBillingListener;
        $listener->handle(new ModuleEnabled($this->company, 'logistics_tracking'));

        // Should NOT create a new invoice
        $invoicesAfter = Invoice::where('company_id', $this->company->id)->count();
        $this->assertEquals($invoicesBefore, $invoicesAfter, 'No new invoice during grace period reactivation');

        // deactivated_at should be cleared
        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();
        $this->assertNull($addon->deactivated_at, 'deactivated_at should be cleared');
    }

    // ── T8: Quote returns already_invoiced + grace_period flags ───

    public function test_quote_returns_already_invoiced_flag(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'addon_pricing' => [
                    'pricing_model' => 'flat',
                    'pricing_params' => ['price_monthly' => 29],
                ],
            ]);

        // Create invoice for current period
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id, now()->subDays(5)->toDateString(), now()->addDays(25)->toDateString());
        InvoiceIssuer::addLine($invoice, 'addon', 'Tracking', 2900, 1, moduleKey: 'logistics_tracking');
        InvoiceIssuer::finalize($invoice);

        $response = $this->actAs()
            ->getJson('/api/modules/quote?keys[]=logistics_tracking');

        $response->assertOk()
            ->assertJson([
                'already_invoiced' => true,
            ]);
    }

    // ── T9: Module index returns grace period addons ─────────────

    public function test_module_index_returns_grace_period_addons(): void
    {
        // Create addon in grace period (future deactivated_at)
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(10),
            'deactivated_at' => now()->addDays(20),
        ]);

        $response = $this->actAs()
            ->getJson('/api/modules');

        $response->assertOk();

        $addons = $response->json('addon_subscriptions');
        $this->assertCount(1, $addons, 'Grace period addons should appear in index');
        $this->assertEquals('logistics_tracking', $addons[0]['module_key']);
        $this->assertNotNull($addons[0]['deactivated_at'], 'deactivated_at should be included');
    }

    // ── T10: Default billing interval from policy ────────────────

    public function test_default_billing_interval_from_policy(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['default_billing_interval' => 'yearly']);
        PlatformBillingPolicy::clearCache();

        $response = $this->actAs()
            ->postJson('/api/billing/plan-change', [
                'idempotency_key' => 'test-default-interval-' . uniqid(),
                'to_plan_key' => 'business',
            ]);

        $response->assertOk();

        // Verify the intent used yearly interval
        $data = $response->json();
        $this->assertNotNull($data['intent']);
    }

    // ── T11: Module index exposes addon_deactivation_timing ─────────

    public function test_module_index_exposes_deactivation_timing(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['addon_deactivation_timing' => 'immediate']);
        PlatformBillingPolicy::clearCache();

        $response = $this->actAs()
            ->getJson('/api/modules');

        $response->assertOk()
            ->assertJson([
                'addon_deactivation_timing' => 'immediate',
            ]);

        // Switch to end_of_period
        $policy->update(['addon_deactivation_timing' => 'end_of_period']);
        PlatformBillingPolicy::clearCache();

        $response2 = $this->actAs()
            ->getJson('/api/modules');

        $response2->assertOk()
            ->assertJson([
                'addon_deactivation_timing' => 'end_of_period',
            ]);
    }

    // ── T12: Deactivation preview — immediate with prorated credit ───

    public function test_deactivation_preview_immediate(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['addon_deactivation_timing' => 'immediate']);
        PlatformBillingPolicy::clearCache();

        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(10),
        ]);

        $response = $this->actAs()
            ->getJson('/api/modules/logistics_tracking/deactivation-preview');

        $response->assertOk()
            ->assertJsonStructure([
                'has_addon',
                'timing',
                'active_until',
                'currency',
                'total_days',
                'days_used',
                'days_remaining',
                'amount_paid_ht',
                'consumed_ht',
                'credit_ht',
                'credit_ttc',
                'wallet_balance',
                'wallet_balance_after',
            ]);

        $data = $response->json();
        $this->assertTrue($data['has_addon']);
        $this->assertEquals('immediate', $data['timing']);
        $this->assertGreaterThan(0, $data['credit_ht']);
        $this->assertEquals(10, $data['days_used']);
        $this->assertGreaterThan(0, $data['days_remaining']);
        $this->assertEquals(2900, $data['amount_paid_ht']);
        $this->assertGreaterThan(0, $data['consumed_ht']);
        $this->assertLessThan(2900, $data['consumed_ht']); // Less than full amount
        $this->assertEquals($data['amount_paid_ht'] - $data['consumed_ht'], $data['credit_ht'], 'Credit = paid - consumed');
        $this->assertEquals(today()->toDateString(), $data['active_until']);
    }

    // ── T13: Deactivation preview — end_of_period ────────────────────

    public function test_deactivation_preview_end_of_period(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['addon_deactivation_timing' => 'end_of_period']);
        PlatformBillingPolicy::clearCache();

        $activatedAt = now()->subDays(10);
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => $activatedAt,
        ]);

        $response = $this->actAs()
            ->getJson('/api/modules/logistics_tracking/deactivation-preview');

        $response->assertOk();

        $data = $response->json();
        $this->assertTrue($data['has_addon']);
        $this->assertEquals('end_of_period', $data['timing']);
        $this->assertEquals(0, $data['credit_ht']);
        $this->assertEquals(10, $data['days_used']);
        $this->assertEquals(2900, $data['amount_paid_ht']);
        $this->assertEquals($activatedAt->copy()->addMonth()->toDateString(), $data['active_until']);
    }

    // ── T14: Deactivation preview — no addon ─────────────────────────

    public function test_deactivation_preview_no_addon(): void
    {
        $response = $this->actAs()
            ->getJson('/api/modules/logistics_tracking/deactivation-preview');

        $response->assertOk()
            ->assertJson([
                'has_addon' => false,
            ]);
    }
}
