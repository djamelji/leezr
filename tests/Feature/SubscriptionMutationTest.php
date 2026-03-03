<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Billing\CompanyWallet;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\InvoiceLine;
use App\Core\Billing\PaymentRegistry;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * ADR-135 D1: Subscription mutation endpoint tests.
 *
 * Covers:
 *   - Plan change (upgrade/downgrade, immediate/scheduled, idempotency, policy enforcement)
 *   - Cancel (immediate/end_of_period, idempotency)
 *   - Pay now (wallet-first, no double payment)
 *   - Permissions (company-scoped, unauthenticated)
 */
class SubscriptionMutationTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private User $owner;
    private Company $company;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();
        PaymentRegistry::boot();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Mutation Co',
            'slug' => 'mutation-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->activateCompanyModules($this->company);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-31'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // PLAN CHANGE
    // ═══════════════════════════════════════════════════════════════

    public function test_plan_change_upgrade_immediate_creates_proration_invoice(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['upgrade_timing' => 'immediate']);

        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        $response = $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'upgrade-imm-1',
            'to_plan_key' => 'business',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Plan change executed.')
            ->assertJsonPath('intent.status', 'executed')
            ->assertJsonPath('intent.to_plan_key', 'business');

        // Proration invoice created
        $invoice = Invoice::where('company_id', $this->company->id)
            ->where('status', '!=', 'draft')
            ->latest()
            ->first();

        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->finalized_at);

        // Company plan updated
        $this->company->refresh();
        $this->assertEquals('business', $this->company->plan_key);
    }

    public function test_plan_change_downgrade_immediate_credits_wallet(): void
    {
        // Set to business first for a clear downgrade
        $this->subscription->update(['plan_key' => 'business']);
        $this->company->update(['plan_key' => 'business']);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['downgrade_timing' => 'immediate']);

        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        $walletBefore = WalletLedger::balance($this->company);

        $response = $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'downgrade-imm-1',
            'to_plan_key' => 'starter',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Plan change executed.')
            ->assertJsonPath('intent.status', 'executed');

        // Wallet credited (business→starter = net negative)
        $walletAfter = WalletLedger::balance($this->company);
        $this->assertGreaterThan($walletBefore, $walletAfter);
    }

    public function test_plan_change_end_of_period_schedules_only(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['upgrade_timing' => 'end_of_period']);

        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        $response = $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'upgrade-eop-1',
            'to_plan_key' => 'business',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Plan change scheduled.')
            ->assertJsonPath('intent.status', 'scheduled')
            ->assertJsonPath('intent.timing', 'end_of_period');

        // Subscription NOT changed yet
        $this->subscription->refresh();
        $this->assertEquals('pro', $this->subscription->plan_key);

        // No invoice created
        $this->assertEquals(0, Invoice::where('company_id', $this->company->id)->count());
    }

    public function test_plan_change_idempotency_replay_safe(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['upgrade_timing' => 'immediate']);

        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        $first = $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'upgrade-idem-1',
            'to_plan_key' => 'business',
        ]);

        $first->assertOk();
        $intentId = $first->json('intent.id');

        // Replay with same idempotency key
        $second = $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'upgrade-idem-1',
            'to_plan_key' => 'business',
        ]);

        $second->assertOk()
            ->assertJsonPath('intent.id', $intentId);

        // Only one intent exists
        $this->assertEquals(1, PlanChangeIntent::where('idempotency_key', 'upgrade-idem-1')->count());
    }

    public function test_plan_change_requires_idempotency_key(): void
    {
        $this->actAs()->postJson('/api/billing/plan-change', [
            'to_plan_key' => 'business',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_plan_change_rejects_invalid_plan(): void
    {
        $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'bad-plan',
            'to_plan_key' => 'nonexistent',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['to_plan_key']);
    }

    public function test_plan_change_rejects_same_plan(): void
    {
        $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'same-plan',
            'to_plan_key' => 'pro',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Already on this plan.');
    }

    public function test_plan_change_no_subscription_returns_422(): void
    {
        $this->subscription->delete();

        $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'no-sub',
            'to_plan_key' => 'business',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'No active subscription.');
    }

    public function test_plan_change_audit_logged(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['upgrade_timing' => 'immediate']);

        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'audit-test',
            'to_plan_key' => 'business',
        ])->assertOk();

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => AuditAction::PLAN_CHANGE_REQUESTED,
        ]);

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => AuditAction::PLAN_CHANGE_EXECUTED,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // CANCEL
    // ═══════════════════════════════════════════════════════════════

    public function test_cancel_immediate(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['downgrade_timing' => 'immediate']);

        $response = $this->actAs()->putJson('/api/billing/subscription/cancel', [
            'idempotency_key' => 'cancel-imm-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription cancelled.')
            ->assertJsonPath('timing', 'immediate');

        $this->subscription->refresh();
        $this->assertEquals('cancelled', $this->subscription->status);
    }

    public function test_cancel_end_of_period(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['downgrade_timing' => 'end_of_period']);

        $response = $this->actAs()->putJson('/api/billing/subscription/cancel', [
            'idempotency_key' => 'cancel-eop-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Subscription will cancel at period end.')
            ->assertJsonPath('timing', 'end_of_period');

        $this->subscription->refresh();
        $this->assertEquals('active', $this->subscription->status);
        $this->assertTrue($this->subscription->cancel_at_period_end);
    }

    public function test_cancel_idempotency_safe(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['downgrade_timing' => 'immediate']);

        // First call
        $first = $this->actAs()->putJson('/api/billing/subscription/cancel', [
            'idempotency_key' => 'cancel-idem-1',
        ]);

        $first->assertOk();

        // Replay — subscription already cancelled, same idempotency key
        $second = $this->actAs()->putJson('/api/billing/subscription/cancel', [
            'idempotency_key' => 'cancel-idem-1',
        ]);

        // Should return OK (idempotent) not 422 "no active subscription"
        // The SubscriptionCanceller returns the existing cancelled subscription
        // because the idempotency key matches the metadata
        $second->assertOk();
    }

    public function test_cancel_requires_idempotency_key(): void
    {
        $this->actAs()->putJson('/api/billing/subscription/cancel', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_cancel_no_subscription_returns_422(): void
    {
        $this->subscription->delete();

        $this->actAs()->putJson('/api/billing/subscription/cancel', [
            'idempotency_key' => 'cancel-nosub',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'No active subscription.');
    }

    public function test_cancel_audit_logged(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['downgrade_timing' => 'immediate']);

        $this->actAs()->putJson('/api/billing/subscription/cancel', [
            'idempotency_key' => 'cancel-audit',
        ])->assertOk();

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => AuditAction::CANCEL_REQUESTED,
        ]);

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => AuditAction::CANCEL_EXECUTED,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // PAY NOW
    // ═══════════════════════════════════════════════════════════════

    public function test_pay_now_wallet_first_respected(): void
    {
        // Disable auto-apply so invoices stay open during finalization
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['auto_apply_wallet_credit' => false]);

        // Credit wallet with 5000 cents
        WalletLedger::credit(
            company: $this->company,
            amount: 5000,
            sourceType: 'admin_adjustment',
            description: 'Test credit',
            actorType: 'admin',
        );

        // Create an open invoice with amount_due 3000
        $invoice = $this->createFinalizedInvoice(3000);
        $this->assertEquals('open', $invoice->status);

        // Re-enable auto_apply so pay-now respects wallet_first
        $policy->update(['auto_apply_wallet_credit' => true]);

        $response = $this->actAs()->postJson('/api/billing/pay-now', [
            'idempotency_key' => 'pay-now-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('invoices_paid', 1)
            ->assertJsonPath('wallet_used', 3000);

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        // Wallet balance reduced
        $this->assertEquals(2000, WalletLedger::balance($this->company));
    }

    public function test_pay_now_no_double_payment_on_replay(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['auto_apply_wallet_credit' => false]);

        WalletLedger::credit(
            company: $this->company,
            amount: 10000,
            sourceType: 'admin_adjustment',
            description: 'Test credit',
            actorType: 'admin',
        );

        $invoice = $this->createFinalizedInvoice(3000);
        $this->assertEquals('open', $invoice->status);

        $policy->update(['auto_apply_wallet_credit' => true]);

        // First call
        $this->actAs()->postJson('/api/billing/pay-now', [
            'idempotency_key' => 'pay-now-replay',
        ])->assertOk()->assertJsonPath('invoices_paid', 1);

        $balanceAfterFirst = WalletLedger::balance($this->company);
        $this->assertEquals(7000, $balanceAfterFirst);

        // Replay — invoice is already paid, wallet transaction idempotent
        $this->actAs()->postJson('/api/billing/pay-now', [
            'idempotency_key' => 'pay-now-replay-2',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'No open invoices.');

        // Balance unchanged
        $this->assertEquals($balanceAfterFirst, WalletLedger::balance($this->company));
    }

    public function test_pay_now_no_open_invoices_returns_422(): void
    {
        $this->actAs()->postJson('/api/billing/pay-now', [
            'idempotency_key' => 'pay-now-empty',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'No open invoices.');
    }

    public function test_pay_now_requires_idempotency_key(): void
    {
        $this->actAs()->postJson('/api/billing/pay-now', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_pay_now_insufficient_wallet_leaves_invoice_open(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['auto_apply_wallet_credit' => false]);

        // Wallet with 1000 cents, invoice needs 3000
        WalletLedger::credit(
            company: $this->company,
            amount: 1000,
            sourceType: 'admin_adjustment',
            description: 'Partial credit',
            actorType: 'admin',
        );

        $invoice = $this->createFinalizedInvoice(3000);
        $this->assertEquals('open', $invoice->status);

        $policy->update(['auto_apply_wallet_credit' => true]);

        $response = $this->actAs()->postJson('/api/billing/pay-now', [
            'idempotency_key' => 'pay-now-partial',
        ]);

        $response->assertOk()
            ->assertJsonPath('invoices_paid', 0)
            ->assertJsonPath('wallet_used', 1000);

        // Invoice partially covered but still open
        $invoice->refresh();
        $this->assertContains($invoice->status, ['open', 'overdue']);
        $this->assertEquals(2000, $invoice->amount_due);

        // Wallet depleted
        $this->assertEquals(0, WalletLedger::balance($this->company));
    }

    public function test_pay_now_audit_logged(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['auto_apply_wallet_credit' => false]);

        WalletLedger::credit(
            company: $this->company,
            amount: 5000,
            sourceType: 'admin_adjustment',
            description: 'Test credit',
            actorType: 'admin',
        );

        $this->createFinalizedInvoice(3000);

        $policy->update(['auto_apply_wallet_credit' => true]);

        $this->actAs()->postJson('/api/billing/pay-now', [
            'idempotency_key' => 'pay-now-audit',
        ])->assertOk();

        $this->assertDatabaseHas('company_audit_logs', [
            'company_id' => $this->company->id,
            'action' => AuditAction::PAID_NOW,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // PERMISSIONS
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_blocked(): void
    {
        $this->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'auth-test',
            'to_plan_key' => 'business',
        ])->assertStatus(401);

        $this->putJson('/api/billing/subscription/cancel', [
            'idempotency_key' => 'auth-test',
        ])->assertStatus(401);

        $this->postJson('/api/billing/pay-now', [
            'idempotency_key' => 'auth-test',
        ])->assertStatus(401);
    }

    public function test_company_only_on_its_subscription(): void
    {
        // Create another company
        $otherCompany = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        Subscription::create([
            'company_id' => $otherCompany->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-31'),
        ]);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['upgrade_timing' => 'immediate']);

        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        // Owner of $this->company acts — can only affect own subscription
        $response = $this->actAs()->postJson('/api/billing/plan-change', [
            'idempotency_key' => 'cross-co-1',
            'to_plan_key' => 'business',
        ]);

        $response->assertOk();

        // Own subscription changed
        $this->subscription->refresh();
        $this->assertEquals('business', $this->subscription->plan_key);

        // Other company's subscription unchanged
        $otherSub = Subscription::where('company_id', $otherCompany->id)->first();
        $this->assertEquals('pro', $otherSub->plan_key);
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create a finalized open invoice with the given amount_due.
     */
    private function createFinalizedInvoice(int $amountDue): Invoice
    {
        $invoice = InvoiceIssuer::createDraft(
            company: $this->company,
            subscriptionId: $this->subscription->id,
        );

        InvoiceIssuer::addLine(
            invoice: $invoice,
            type: 'plan',
            description: 'Plan charge',
            unitAmount: $amountDue,
            quantity: 1,
        );

        return InvoiceIssuer::finalize($invoice);
    }
}
