<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformPaymentModule;
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
 * ADR-224: Addon Module Billing (LOT D).
 *
 * Tests: addon billing listener, addon credit listener, renewal with addons.
 */
class BillingLotDTest extends TestCase
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

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'LotD Co',
            'slug' => 'lotd-co',
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

        // Set addon_pricing on a known module
        PlatformModule::where('key', 'logistics_tracking')->update([
            'addon_pricing' => [
                'pricing_model' => 'flat',
                'pricing_metric' => 'none',
                'pricing_params' => ['price_monthly' => 29],
            ],
        ]);
    }

    // ── D1: AddonBillingListener ─────────────────────────────

    public function test_enabling_addon_creates_addon_subscription(): void
    {
        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($addon, 'Addon subscription should be created');
        $this->assertEquals(2900, $addon->amount_cents);
        $this->assertEquals('monthly', $addon->interval);
        $this->assertNull($addon->deactivated_at);
    }

    public function test_enabling_addon_generates_invoice(): void
    {
        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        $line = InvoiceLine::where('type', 'addon')
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($line, 'Addon invoice line should be created');
        $this->assertEquals(2900, $line->unit_amount);

        $invoice = Invoice::find($line->invoice_id);
        $this->assertNotNull($invoice->finalized_at, 'Invoice should be finalized');
    }

    public function test_enabling_non_addon_module_creates_no_billing(): void
    {
        // logistics_shipments has no addon_pricing (it's a core/included module)
        PlatformModule::where('key', 'logistics_shipments')->update(['addon_pricing' => null]);

        ModuleEnabled::dispatch($this->company, 'logistics_shipments');

        $this->assertEquals(0, CompanyAddonSubscription::count(), 'No addon subscription for non-addon module');
        $this->assertEquals(0, Invoice::count(), 'No invoice for non-addon module');
    }

    // ── D2: AddonCreditListener ──────────────────────────────

    public function test_disabling_addon_deactivates_subscription(): void
    {
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(5),
        ]);

        ModuleDisabled::dispatch($this->company, 'logistics_tracking');

        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($addon->deactivated_at, 'Addon should be deactivated');
    }

    public function test_disabling_addon_does_not_create_credit_note(): void
    {
        // ADR-328 S7: No prorated credit on deactivation
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(5),
        ]);

        ModuleDisabled::dispatch($this->company, 'logistics_tracking');

        $creditNote = CreditNote::where('company_id', $this->company->id)->first();
        $this->assertNull($creditNote, 'No credit note should be created on deactivation (ADR-328 S7)');

        $balance = WalletLedger::balance($this->company);
        $this->assertEquals(0, $balance, 'Wallet should remain unchanged');
    }

    // ── D3: Renewal includes addon lines ─────────────────────

    public function test_renewal_includes_addon_lines(): void
    {
        // Expire the subscription so billing:renew picks it up
        $this->subscription->update([
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
        ]);

        // Create active addon subscription
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(20),
        ]);

        $this->artisan('billing:renew')->assertSuccessful();

        $invoice = Invoice::where('subscription_id', $this->subscription->id)->first();
        $this->assertNotNull($invoice, 'Renewal invoice should exist');

        $lines = InvoiceLine::where('invoice_id', $invoice->id)->get();
        $planLine = $lines->where('type', 'plan')->first();
        $addonLine = $lines->where('type', 'addon')->first();

        $this->assertNotNull($planLine, 'Plan line should exist');
        $this->assertNotNull($addonLine, 'Addon line should exist');
        $this->assertEquals('logistics_tracking', $addonLine->module_key);
        $this->assertEquals(2900, $addonLine->unit_amount);
    }

    public function test_disabled_addon_not_in_renewal(): void
    {
        $this->subscription->update([
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
        ]);

        // Create deactivated addon subscription
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(20),
            'deactivated_at' => now()->subDays(5),
        ]);

        $this->artisan('billing:renew')->assertSuccessful();

        $invoice = Invoice::where('subscription_id', $this->subscription->id)->first();
        $this->assertNotNull($invoice);

        $addonLines = InvoiceLine::where('invoice_id', $invoice->id)
            ->where('type', 'addon')
            ->count();

        $this->assertEquals(0, $addonLines, 'Deactivated addon should not appear in renewal');
    }
}
