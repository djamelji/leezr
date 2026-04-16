<?php

namespace Tests\Feature;

use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Notifications\Billing\AccountSuspended;
use App\Notifications\Billing\PaymentFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-223/226: Auto-Renewal + Dunning UX (LOT C).
 *
 * Tests: billing:renew command, dunning notifications, suspension emails.
 */
class BillingLotCTest extends TestCase
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
            'name' => 'LotC Co',
            'slug' => 'lotc-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
    }

    // ── C1: billing:renew creates renewal invoice ───────────

    public function test_billing_renew_creates_renewal_invoice(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('billing:renew')
            ->assertSuccessful();

        // Invoice should be created for the renewal period
        $invoice = Invoice::where('subscription_id', $sub->id)
            ->whereDate('period_start', $sub->current_period_end->toDateString())
            ->first();

        $this->assertNotNull($invoice, 'Renewal invoice should be created');
        $this->assertNotNull($invoice->finalized_at, 'Invoice should be finalized');
    }

    public function test_billing_renew_extends_period_for_free_plan(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
        ]);

        $oldEnd = $sub->current_period_end->copy();

        $this->artisan('billing:renew')
            ->assertSuccessful();

        $sub->refresh();
        $this->assertTrue($sub->current_period_end->gt($oldEnd), 'Period should be extended');
        $this->assertEquals($oldEnd->toDateString(), $sub->current_period_start->toDateString());
    }

    public function test_billing_renew_is_idempotent(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
        ]);

        // Run twice
        $this->artisan('billing:renew')->assertSuccessful();
        $this->artisan('billing:renew')->assertSuccessful();

        // Should only have 1 invoice
        $invoices = Invoice::where('subscription_id', $sub->id)->count();
        $this->assertEquals(1, $invoices, 'Should not create duplicate invoices');
    }

    public function test_billing_renew_dry_run_creates_nothing(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->subDay(),
        ]);

        $this->artisan('billing:renew --dry-run')
            ->assertSuccessful();

        $this->assertEquals(0, Invoice::count(), 'Dry run should not create invoices');
    }

    public function test_billing_renew_skips_non_expired_subscriptions(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20), // Not expired yet
        ]);

        $this->artisan('billing:renew')
            ->assertSuccessful();

        $this->assertEquals(0, Invoice::count(), 'Should not create invoice for non-expired sub');
    }

    public function test_billing_renew_converts_trialing_to_active(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'status' => 'trialing',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->subDay(),
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->artisan('billing:renew')
            ->assertSuccessful();

        $sub->refresh();
        $this->assertEquals('active', $sub->status);
        $this->assertNull($sub->trial_ends_at);
    }

    // ── C4: Dunning notifications ───────────────────────────

    public function test_dunning_sends_payment_failed_notification(): void
    {
        Notification::fake();

        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->addDays(30),
        ]);

        // Create an overdue invoice with next_retry_at in the past
        $invoice = InvoiceIssuer::createDraft($this->company, $sub->id, now()->subDays(31)->toDateString(), now()->toDateString());
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900, 1);
        $invoice = InvoiceIssuer::finalize($invoice);

        // Mark as overdue and schedule retry
        $invoice->update([
            'status' => 'overdue',
            'next_retry_at' => now()->subHour(),
            'retry_count' => 0,
        ]);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 3]);

        DunningEngine::processOverdueInvoices();

        Notification::assertSentTo($this->owner, PaymentFailed::class);
    }

    public function test_dunning_sends_account_suspended_notification(): void
    {
        Notification::fake();

        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'past_due',
            'provider' => 'internal',
            'is_current' => 1,
            'current_period_start' => now()->subDays(31),
            'current_period_end' => now()->addDays(30),
        ]);

        // Create overdue invoice at max retries
        $invoice = InvoiceIssuer::createDraft($this->company, $sub->id, now()->subDays(31)->toDateString(), now()->toDateString());
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900, 1);
        $invoice = InvoiceIssuer::finalize($invoice);

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 1, 'failure_action' => 'suspend']);

        $invoice->update([
            'status' => 'overdue',
            'next_retry_at' => now()->subHour(),
            'retry_count' => 0,
        ]);

        DunningEngine::processOverdueInvoices();

        Notification::assertSentTo($this->owner, AccountSuspended::class);

        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        $sub->refresh();
        $this->assertEquals('suspended', $sub->status);
        $this->assertNull($sub->is_current);
    }

    // ── Notification rendering ──────────────────────────────

    public function test_payment_failed_notification_renders_mail(): void
    {
        $invoice = InvoiceIssuer::createDraft($this->company);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900, 1);
        $invoice = InvoiceIssuer::finalize($invoice);

        $notification = new PaymentFailed($invoice);
        $mail = $notification->toMail($this->owner);

        $rendered = $mail->render();
        $html = is_string($rendered) ? $rendered : $rendered->toHtml();

        $this->assertStringContainsString('payment_failed', $html);
    }

    public function test_account_suspended_notification_renders_mail(): void
    {
        $notification = new AccountSuspended();
        $mail = $notification->toMail($this->owner);

        $rendered = $mail->render();
        $html = is_string($rendered) ? $rendered : $rendered->toHtml();

        $this->assertStringContainsString('account_suspended', $html);
    }
}
