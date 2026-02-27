<?php

namespace Tests\Feature;

use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlanChangeExecutor;
use App\Core\Billing\PlanChangeIntent;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dunning Engine E2E tests.
 *
 * Policy matrix under test:
 *   grace_period_days: 3
 *   max_retry_attempts: 3
 *   retry_intervals_days: [1, 3, 7]
 *   failure_action: suspend
 *
 * Tests:
 *   1. Open invoice within grace period → not processed
 *   2. Open invoice past grace period → marked overdue + next_retry_at set + subscription past_due
 *   3. Overdue invoice retried with wallet balance → paid + subscription reactivated
 *   4. Overdue invoice retried without balance → retry_count increments, rescheduled
 *   5. Max retries exhausted → uncollectible + subscription suspended + company suspended
 *   6. failure_action=cancel → subscription cancelled + PlanChangeIntents cancelled + plan_key downgraded
 *   7. Idempotency: running twice does not double-process
 *   8. Voided invoices are never processed
 *   9. Draft invoices are never processed
 *  10. Already paid invoices are never processed
 *  11. Artisan command runs without error
 *  12. Full lifecycle: open → overdue → retry → retry → exhausted → suspended
 *  13. Suspension is idempotent
 *  14. Wallet payment uses correct idempotency key per retry
 *  15. Subscription becomes past_due when invoice goes overdue
 *  16. Reactivation when all overdue invoices paid
 *  17. No reactivation when uncollectible invoices remain
 *  18. Cancel action cancels scheduled PlanChangeIntents
 *  19. Cancel action downgrades company plan_key to starter
 *  20. Command implements Isolatable
 */
class DunningEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();

        $this->company = Company::create([
            'name' => 'Dunning Co',
            'slug' => 'dunning-co',
            'plan_key' => 'pro',
            'status' => 'active',
        ]);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => Carbon::parse('2026-03-01'),
            'current_period_end' => Carbon::parse('2026-03-31'),
        ]);
    }

    private function createOverdueInvoice(int $amountDue = 2900, ?string $dueAt = null): Invoice
    {
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', $amountDue);
        $finalized = InvoiceIssuer::finalize($invoice);

        // Override due_at to simulate an overdue invoice
        $finalized->update([
            'due_at' => $dueAt ?? Carbon::parse('2026-03-01'),
        ]);

        return $finalized->fresh();
    }

    // ── 1. Grace period not exceeded → not processed ──

    public function test_invoice_within_grace_period_not_processed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        // Due yesterday, grace=3 → not overdue yet
        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-09')->toDateTimeString());

        $stats = DunningEngine::processOverdueInvoices();

        $this->assertEquals(0, $stats['processed']);

        $invoice->refresh();
        $this->assertEquals('open', $invoice->status);

        // Subscription unchanged
        $this->subscription->refresh();
        $this->assertEquals('active', $this->subscription->status);

        Carbon::setTestNow();
    }

    // ── 2. Past grace period → marked overdue + subscription past_due ──

    public function test_invoice_past_grace_period_marked_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        // Due 5 days ago, grace=3 → overdue
        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        $stats = DunningEngine::processOverdueInvoices();

        $this->assertEquals(1, $stats['processed']);

        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
        $this->assertNotNull($invoice->next_retry_at);

        // next_retry_at = now + retry_intervals_days[0] = 1 day
        $this->assertEquals('2026-03-11', $invoice->next_retry_at->toDateString());

        // Subscription transitions to past_due
        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);

        Carbon::setTestNow();
    }

    // ── 3. Retry with wallet balance → paid + subscription reactivated ──

    public function test_overdue_invoice_paid_by_wallet_on_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // First run: mark overdue
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);

        // Subscription is past_due
        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);

        // Credit wallet with enough balance
        WalletLedger::credit(
            $this->company, 5000, 'admin_adjustment',
            actorType: 'platform_user', actorId: 1,
        );

        // Move to retry time
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));

        $stats = DunningEngine::processOverdueInvoices();
        $this->assertEquals(1, $stats['retried']);

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNull($invoice->next_retry_at);

        // Wallet debited
        $this->assertEquals(5000 - $invoice->amount_due, WalletLedger::balance($this->company));

        // Subscription reactivated
        $this->subscription->refresh();
        $this->assertEquals('active', $this->subscription->status);

        Carbon::setTestNow();
    }

    // ── 4. Retry without balance → rescheduled ──

    public function test_overdue_invoice_rescheduled_when_no_balance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue
        DunningEngine::processOverdueInvoices();

        // Move to retry time — no wallet balance
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));

        $stats = DunningEngine::processOverdueInvoices();
        $this->assertEquals(1, $stats['retried']);

        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
        $this->assertEquals(1, $invoice->retry_count);

        // Next retry: now + retry_intervals_days[1] = 3 days
        $this->assertEquals('2026-03-14', $invoice->next_retry_at->toDateString());

        // Subscription still past_due
        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);

        Carbon::setTestNow();
    }

    // ── 5. Max retries exhausted → uncollectible + subscription suspended + company suspended ──

    public function test_max_retries_exhausted_marks_uncollectible_and_suspends(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // Phase 1: mark overdue
        DunningEngine::processOverdueInvoices();

        // Retry 1 (day 11)
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertEquals(1, $invoice->retry_count);

        // Retry 2 (day 14)
        Carbon::setTestNow(Carbon::parse('2026-03-14 01:00:00'));
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertEquals(2, $invoice->retry_count);

        // Retry 3 (day 21) — max_retry_attempts=3 → exhausted
        Carbon::setTestNow(Carbon::parse('2026-03-21 01:00:00'));
        $stats = DunningEngine::processOverdueInvoices();
        $this->assertEquals(1, $stats['exhausted']);

        $invoice->refresh();
        $this->assertEquals('uncollectible', $invoice->status);
        $this->assertEquals(3, $invoice->retry_count);
        $this->assertNull($invoice->next_retry_at);

        // Subscription suspended
        $this->subscription->refresh();
        $this->assertEquals('suspended', $this->subscription->status);

        // Company suspended
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        Carbon::setTestNow();
    }

    // ── 6. failure_action=cancel → subscription cancelled + company suspended ──

    public function test_failure_action_cancel_cancels_subscription(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'failure_action' => 'cancel',
            'max_retry_attempts' => 1,
            'retry_intervals_days' => [1],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue
        DunningEngine::processOverdueInvoices();

        // Retry 1 → exhausted
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();

        $invoice->refresh();
        $this->assertEquals('uncollectible', $invoice->status);

        // Subscription cancelled
        $this->subscription->refresh();
        $this->assertEquals('cancelled', $this->subscription->status);

        // Company suspended + plan_key downgraded to starter
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);
        $this->assertEquals('starter', $this->company->plan_key);

        Carbon::setTestNow();
    }

    // ── 7. Idempotency: running twice does not double-process ──

    public function test_idempotency_no_double_processing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // First run
        $stats1 = DunningEngine::processOverdueInvoices();
        $this->assertEquals(1, $stats1['processed']);

        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);

        // Second run — same time, invoice already overdue
        $stats2 = DunningEngine::processOverdueInvoices();
        $this->assertEquals(0, $stats2['processed']);

        Carbon::setTestNow();
    }

    // ── 8. Voided invoices are never processed ──

    public function test_voided_invoices_not_processed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());
        $invoice->update(['voided_at' => now()]);

        $stats = DunningEngine::processOverdueInvoices();

        $this->assertEquals(0, $stats['processed']);

        Carbon::setTestNow();
    }

    // ── 9. Draft invoices are never processed ──

    public function test_draft_invoices_not_processed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = InvoiceIssuer::createDraft($this->company);
        $invoice->update(['due_at' => Carbon::parse('2026-03-01')]);

        $stats = DunningEngine::processOverdueInvoices();

        $this->assertEquals(0, $stats['processed']);

        Carbon::setTestNow();
    }

    // ── 10. Already paid invoices are never processed ──

    public function test_paid_invoices_not_processed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        // Credit wallet so invoice is paid on finalize
        WalletLedger::credit(
            $this->company, 5000, 'admin_adjustment',
            actorType: 'platform_user', actorId: 1,
        );

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // Invoice should be paid (wallet covers it)
        $this->assertEquals('paid', $invoice->status);

        $stats = DunningEngine::processOverdueInvoices();

        $this->assertEquals(0, $stats['processed']);

        Carbon::setTestNow();
    }

    // ── 11. Artisan command runs ──

    public function test_artisan_command_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $this->artisan('billing:process-dunning')
            ->expectsOutput('Processing overdue invoices...')
            ->assertExitCode(0);

        Carbon::setTestNow();
    }

    // ── 12. Full lifecycle: open → overdue → retry → exhausted → suspended ──

    public function test_full_dunning_lifecycle(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'grace_period_days' => 2,
            'max_retry_attempts' => 2,
            'retry_intervals_days' => [1, 2],
            'failure_action' => 'suspend',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-02-27')->toDateTimeString());

        // Day 1: past grace → overdue, subscription → past_due
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
        $this->assertEquals(0, $invoice->retry_count);
        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);

        // Day 2: retry 1 → no balance → rescheduled
        Carbon::setTestNow(Carbon::parse('2026-03-02 01:00:00'));
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertEquals('overdue', $invoice->status);
        $this->assertEquals(1, $invoice->retry_count);

        // Day 4: retry 2 → max reached → uncollectible
        Carbon::setTestNow(Carbon::parse('2026-03-04 01:00:00'));
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertEquals('uncollectible', $invoice->status);
        $this->assertEquals(2, $invoice->retry_count);

        // Subscription suspended
        $this->subscription->refresh();
        $this->assertEquals('suspended', $this->subscription->status);

        // Company suspended
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        Carbon::setTestNow();
    }

    // ── 13. Suspension is idempotent ──

    public function test_suspension_idempotent(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 1, 'retry_intervals_days' => [1]]);

        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        // Create 2 invoices that will both become uncollectible
        $invoice1 = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());
        $invoice2 = $this->createOverdueInvoice(1500, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue
        DunningEngine::processOverdueInvoices();

        // Exhaust both
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        $stats = DunningEngine::processOverdueInvoices();

        $this->assertEquals(2, $stats['exhausted']);

        // Company suspended only once (idempotent)
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        Carbon::setTestNow();
    }

    // ── 14. Wallet payment uses correct idempotency key per retry ──

    public function test_wallet_payment_idempotency_key_unique_per_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue
        DunningEngine::processOverdueInvoices();

        // First retry — no balance
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();

        // Now add wallet balance and retry
        WalletLedger::credit(
            $this->company, 5000, 'admin_topup',
            actorType: 'platform_user', actorId: 1,
        );

        Carbon::setTestNow(Carbon::parse('2026-03-14 01:00:00'));
        DunningEngine::processOverdueInvoices();

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(2, $invoice->retry_count);

        Carbon::setTestNow();
    }

    // ── 15. Subscription becomes past_due when invoice goes overdue ──

    public function test_subscription_becomes_past_due_on_overdue_invoice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $this->assertEquals('active', $this->subscription->status);

        $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        DunningEngine::processOverdueInvoices();

        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);

        Carbon::setTestNow();
    }

    // ── 16. Reactivation when all overdue invoices paid ──

    public function test_reactivation_when_all_overdue_invoices_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        // Create 2 overdue invoices
        $invoice1 = $this->createOverdueInvoice(2000, Carbon::parse('2026-03-05')->toDateTimeString());
        $invoice2 = $this->createOverdueInvoice(1000, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue
        DunningEngine::processOverdueInvoices();

        $this->subscription->refresh();
        $this->assertEquals('past_due', $this->subscription->status);

        // Credit wallet to cover both
        WalletLedger::credit(
            $this->company, 5000, 'admin_topup',
            actorType: 'platform_user', actorId: 1,
        );

        // Retry → both paid
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();

        $invoice1->refresh();
        $invoice2->refresh();
        $this->assertEquals('paid', $invoice1->status);
        $this->assertEquals('paid', $invoice2->status);

        // Subscription reactivated
        $this->subscription->refresh();
        $this->assertEquals('active', $this->subscription->status);

        Carbon::setTestNow();
    }

    // ── 17. No reactivation when uncollectible invoices remain ──

    public function test_no_reactivation_when_uncollectible_remains(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['max_retry_attempts' => 1, 'retry_intervals_days' => [1]]);

        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        // Invoice 1: will become uncollectible (no wallet balance)
        $invoice1 = $this->createOverdueInvoice(5000, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue
        DunningEngine::processOverdueInvoices();

        // Exhaust invoice1 → company suspended
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();

        $invoice1->refresh();
        $this->assertEquals('uncollectible', $invoice1->status);
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        // Now create invoice2 that goes overdue and gets paid
        $invoice2 = $this->createOverdueInvoice(1000, Carbon::parse('2026-03-06')->toDateTimeString());

        // Credit wallet for invoice2
        WalletLedger::credit(
            $this->company, 2000, 'admin_topup',
            actorType: 'platform_user', actorId: 1,
        );

        // Mark overdue + retry pays it
        Carbon::setTestNow(Carbon::parse('2026-03-12'));
        DunningEngine::processOverdueInvoices();

        Carbon::setTestNow(Carbon::parse('2026-03-13 01:00:00'));
        DunningEngine::processOverdueInvoices();

        $invoice2->refresh();
        $this->assertEquals('paid', $invoice2->status);

        // Company still suspended — uncollectible invoice1 blocks reactivation
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        Carbon::setTestNow();
    }

    // ── 18. Cancel action cancels scheduled PlanChangeIntents ──

    public function test_cancel_action_cancels_scheduled_plan_change_intents(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'failure_action' => 'cancel',
            'max_retry_attempts' => 1,
            'retry_intervals_days' => [1],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        // Create a scheduled PlanChangeIntent
        $intent = PlanChangeIntent::create([
            'company_id' => $this->company->id,
            'from_plan_key' => 'pro',
            'to_plan_key' => 'business',
            'interval_from' => 'monthly',
            'interval_to' => 'monthly',
            'timing' => 'end_of_period',
            'effective_at' => Carbon::parse('2026-03-31'),
            'status' => 'scheduled',
        ]);

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue
        DunningEngine::processOverdueInvoices();

        // Exhaust → cancel action
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();

        // PlanChangeIntent cancelled
        $intent->refresh();
        $this->assertEquals('cancelled', $intent->status);

        Carbon::setTestNow();
    }

    // ── 19. Cancel action downgrades company plan_key to starter ──

    public function test_cancel_action_downgrades_plan_key_to_starter(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update([
            'failure_action' => 'cancel',
            'max_retry_attempts' => 1,
            'retry_intervals_days' => [1],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-10'));

        $this->assertEquals('pro', $this->company->plan_key);

        $invoice = $this->createOverdueInvoice(2900, Carbon::parse('2026-03-05')->toDateTimeString());

        // Mark overdue + exhaust
        DunningEngine::processOverdueInvoices();
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();

        $this->company->refresh();
        $this->assertEquals('starter', $this->company->plan_key);
        $this->assertEquals('suspended', $this->company->status);

        Carbon::setTestNow();
    }

    // ── 20. Command implements Isolatable ──

    public function test_command_implements_isolatable(): void
    {
        $command = new \App\Console\Commands\ProcessDunningCommand();
        $this->assertInstanceOf(\Illuminate\Contracts\Console\Isolatable::class, $command);
    }
}
