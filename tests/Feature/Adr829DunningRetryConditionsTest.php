<?php

namespace Tests\Feature;

use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;
use App\Jobs\Billing\ProcessDunningBatchJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-829 (D-17) — non-regression of the dunning retry conditions, WITHOUT changing any financial rule.
 * Policy : grace 3 days, 3 attempts, intervals [1, 3, 7], failure action suspend (PlatformSeeder).
 *
 * The deferred-job defect (a late or duplicated ProcessDunningBatchJob does not re-check next_retry_at)
 * is NOT fixed here : the correction is prepared in docs/bmad/leezr-3-9/billing/ and the async path stays
 * locked (BillingAsyncGate, ADR-828). This test pins what holds today.
 */
class Adr829DunningRetryConditionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();
        $this->company = Company::create(['name' => 'Dun829', 'slug' => 'dun829', 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique']);
        $this->subscription = Subscription::create(['company_id' => $this->company->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'current_period_start' => Carbon::parse('2026-03-01'), 'current_period_end' => Carbon::parse('2026-03-31')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Overdue invoice, first retry due 2026-03-11. */
    private function overdue(): Invoice
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($invoice);
        $invoice->update(['due_at' => Carbon::parse('2026-03-05')]);
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertSame('overdue', $invoice->status);
        $this->assertSame('2026-03-11', $invoice->next_retry_at->toDateString());

        return $invoice;
    }

    private function snapshot(Invoice $invoice): array
    {
        $invoice->refresh();

        return [$invoice->status, $invoice->retry_count, $invoice->next_retry_at?->toDateTimeString(), $invoice->amount_due];
    }

    public function test_the_scheduled_scan_never_retries_before_next_retry_at(): void
    {
        $invoice = $this->overdue();
        $before = $this->snapshot($invoice);
        Carbon::setTestNow(Carbon::parse('2026-03-10 23:59:00'));
        $stats = DunningEngine::processOverdueInvoices();
        $this->assertSame(0, $stats['retried']);
        $this->assertSame($before, $this->snapshot($invoice));
    }

    public function test_a_second_scan_the_same_day_does_not_retry_twice(): void
    {
        $invoice = $this->overdue();
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();
        $after = $this->snapshot($invoice);
        $this->assertSame(1, $after[1]);
        Carbon::setTestNow(Carbon::parse('2026-03-11 02:00:00'));
        DunningEngine::processOverdueInvoices();
        $this->assertSame($after, $this->snapshot($invoice), 'duplicate scan : no extra attempt');
    }

    /** Paid meanwhile, cancelled (void), already uncollectible : the job touches nothing. */
    public function test_the_job_skips_an_invoice_that_is_no_longer_overdue(): void
    {
        foreach (['paid' => ['status' => 'paid', 'paid_at' => now(), 'amount_due' => 0], 'void' => ['status' => 'void', 'voided_at' => now()],
            'uncollectible' => ['status' => 'uncollectible', 'next_retry_at' => null]] as $label => $state) {
            $invoice = $this->overdue();
            $invoice->update($state);
            $before = $this->snapshot($invoice);
            Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
            (new ProcessDunningBatchJob(collect([$invoice->id])))->handle();
            $this->assertSame($before, $this->snapshot($invoice), $label);
            $this->assertSame('past_due', $this->subscription->fresh()->status, $label);
            $invoice->update(['status' => 'void', 'voided_at' => now()]); // isolate the next case
        }
        $this->assertSame('active', $this->company->fresh()->status);
    }

    public function test_a_paid_invoice_is_not_charged_again_by_a_job(): void
    {
        $invoice = $this->overdue();
        WalletLedger::credit($this->company, 5000, 'admin_adjustment', actorType: 'platform_user', actorId: 1);
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();
        $this->assertSame('paid', $invoice->fresh()->status);
        $balance = WalletLedger::balance($this->company);

        (new ProcessDunningBatchJob(collect([$invoice->id])))->handle();
        (new ProcessDunningBatchJob(collect([$invoice->id])))->handle();
        $this->assertSame($balance, WalletLedger::balance($this->company), 'wallet debited once');
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /** A suspended subscription keeps the existing rule : its overdue invoices are still dunned, nothing new. */
    public function test_an_overdue_invoice_of_a_suspended_subscription_follows_the_existing_rule(): void
    {
        $invoice = $this->overdue();
        $this->subscription->update(['status' => 'suspended']);
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        DunningEngine::processOverdueInvoices();
        $this->assertSame(['overdue', 1], array_slice($this->snapshot($invoice), 0, 2));
        $this->assertSame('suspended', $this->subscription->fresh()->status, 'no reactivation without payment');
    }

    public function test_async_stays_locked_and_dispatches_nothing(): void
    {
        config(['billing.async_queues_enabled' => false]);
        Queue::fake();
        $invoice = $this->overdue();
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00:00'));
        $before = $this->snapshot($invoice);
        $this->assertSame(1, Artisan::call('billing:process-dunning', ['--async' => true]));
        Queue::assertNothingPushed();
        $this->assertSame($before, $this->snapshot($invoice));
    }
}
