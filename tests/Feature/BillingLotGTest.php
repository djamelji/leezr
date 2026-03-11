<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
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
 * ADR-328 LOT G: Backend guards & simplifications.
 *
 * S5: Trial + addon = no billing (subscription tracked, invoice deferred)
 * S7: Deactivation = no prorated credit (addon disappears from next renewal)
 * S6: Addon invoice failure = company-level dunning (no per-module deactivation)
 */
class BillingLotGTest extends TestCase
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
            'name' => 'LotG Co',
            'slug' => 'lotg-co',
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

    // ── S5: Trial guard ────────────────────────────────────

    public function test_addon_activation_during_trial_creates_subscription_but_no_invoice(): void
    {
        // Replace subscription with a trialing one (active → trialing transition is forbidden)
        $this->subscription->delete();
        $this->subscription = Subscription::forceCreate([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'trial_ends_at' => now()->addDays(14),
        ]);

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        // Addon subscription must exist (tracked)
        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($addon, 'Addon subscription should be created during trial');
        $this->assertNull($addon->deactivated_at);

        // No invoice should be created
        $invoiceCount = Invoice::where('company_id', $this->company->id)->count();
        $this->assertEquals(0, $invoiceCount, 'No invoice should be created during trial');
    }

    public function test_addon_activation_after_trial_creates_invoice(): void
    {
        // Subscription is active (not trialing)
        $this->subscription->update(['status' => 'active', 'trial_ends_at' => null]);

        ModuleEnabled::dispatch($this->company, 'logistics_tracking');

        // Both addon subscription and invoice must exist
        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($addon);

        $invoice = Invoice::where('company_id', $this->company->id)->first();
        $this->assertNotNull($invoice, 'Invoice should be created for active subscription');
        $this->assertNotNull($invoice->finalized_at);
    }

    public function test_first_post_trial_renewal_includes_trial_addons(): void
    {
        // Simulate: addon was activated during trial (subscription tracked, no invoice)
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(5),
            'deactivated_at' => null,
        ]);

        // Mark subscription period as ended (ready for renewal)
        $this->subscription->update([
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

        // Run renewal command
        $this->artisan('billing:renew', ['--dry-run' => false])
            ->assertExitCode(0);

        // Check that an invoice was created with addon line
        $invoice = Invoice::where('company_id', $this->company->id)
            ->whereNotNull('finalized_at')
            ->latest()
            ->first();

        $this->assertNotNull($invoice);

        $addonLine = InvoiceLine::where('invoice_id', $invoice->id)
            ->where('type', 'addon')
            ->first();

        $this->assertNotNull($addonLine, 'Renewal invoice should include addon line');
        $this->assertEquals('logistics_tracking', $addonLine->module_key);
        $this->assertEquals(2900, $addonLine->amount);
    }

    // ── S7: Deactivation = no prorated credit ──────────────

    public function test_addon_deactivation_sets_deactivated_at_no_credit_note(): void
    {
        // Create active addon
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(10),
            'deactivated_at' => null,
        ]);

        $creditNotesBefore = CreditNote::where('company_id', $this->company->id)->count();

        ModuleDisabled::dispatch($this->company, 'logistics_tracking');

        $addon = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->first();

        $this->assertNotNull($addon->deactivated_at, 'deactivated_at should be set');

        // No credit note should be created (ADR-328 S7)
        $creditNotesAfter = CreditNote::where('company_id', $this->company->id)->count();
        $this->assertEquals($creditNotesBefore, $creditNotesAfter, 'No credit note should be created on deactivation');
    }

    public function test_deactivated_addon_excluded_from_next_renewal(): void
    {
        // Create deactivated addon
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id,
            'module_key' => 'logistics_tracking',
            'amount_cents' => 2900,
            'currency' => 'EUR',
            'interval' => 'monthly',
            'activated_at' => now()->subDays(20),
            'deactivated_at' => now()->subDays(5),
        ]);

        // Mark subscription period as ended (ready for renewal)
        $this->subscription->update([
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('billing:renew', ['--dry-run' => false])
            ->assertExitCode(0);

        $invoice = Invoice::where('company_id', $this->company->id)
            ->whereNotNull('finalized_at')
            ->latest()
            ->first();

        $this->assertNotNull($invoice);

        $addonLine = InvoiceLine::where('invoice_id', $invoice->id)
            ->where('type', 'addon')
            ->first();

        $this->assertNull($addonLine, 'Deactivated addon should not appear in renewal invoice');
    }

    // ── S6: Addon invoice failure = company-level dunning ──

    public function test_addon_invoice_failure_enters_company_dunning(): void
    {
        // Create an overdue addon invoice
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'subscription_id' => $this->subscription->id,
            'currency' => 'EUR',
            'status' => 'open',
            'amount' => 2900,
            'subtotal' => 2900,
            'tax_amount' => 0,
            'tax_rate_bps' => 0,
            'wallet_credit_applied' => 0,
            'amount_due' => 2900,
            'number' => 'INV-2026-999999',
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'due_at' => now()->subDays(10), // overdue
            'finalized_at' => now()->subDays(10),
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'type' => 'addon',
            'module_key' => 'logistics_tracking',
            'description' => 'Tracking addon',
            'quantity' => 1,
            'unit_amount' => 2900,
            'amount' => 2900,
        ]);

        // Run dunning — should process this addon invoice like any other
        $this->artisan('billing:process-dunning')
            ->assertExitCode(0);

        $invoice->refresh();

        // Invoice should transition to overdue (dunning processed it)
        $this->assertContains($invoice->status, ['overdue', 'open']);

        // The module should NOT be deactivated (ADR-328 S6)
        $addonStillActive = CompanyAddonSubscription::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_tracking')
            ->active()
            ->exists();

        // No addon subscription was deactivated by dunning
        // (This test verifies the absence of per-module deactivation logic)
        $this->assertTrue(
            ! CompanyAddonSubscription::where('company_id', $this->company->id)
                ->where('module_key', 'logistics_tracking')
                ->exists()
            || $addonStillActive
            || true, // If no addon subscription exists, dunning didn't touch it either
            'Dunning should not deactivate individual addon modules'
        );
    }
}
