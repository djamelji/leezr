<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\DunningEngine;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Models\Company;
use App\Core\Plans\PlanRegistry;
use App\Jobs\Billing\ProcessDunningBatchJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ADR-830 (D-17) — one dunning attempt per scheduled due date, whatever the delivery : late job,
 * redelivered job, concurrent worker, scheduled scan + job, provider error, interruption.
 * Policy unchanged (PlatformSeeder : grace 3 d, 3 attempts, intervals [1, 3, 7], suspend).
 * Provider calls are simulated (no network, no real payment) ; every invoice is fictive.
 */
class Adr830DunningConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Subscription $subscription;

    /** @var array<int, string> provider calls recorded by the fake adapter */
    private array $calls = [];

    private string $providerMode = 'fail';

    private ?\Closure $duringCharge = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();
        $this->company = Company::create(['name' => 'Dun830', 'slug' => 'dun830', 'plan_key' => 'pro', 'status' => 'active', 'jobdomain_key' => 'logistique']);
        $this->subscription = Subscription::create(['company_id' => $this->company->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'current_period_start' => Carbon::parse('2026-03-01'), 'current_period_end' => Carbon::parse('2026-03-31')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A saved card + a fake Stripe adapter : 'fail' | 'throw' | 'succeed'. */
    private function withProvider(string $mode): void
    {
        $this->providerMode = $mode;
        CompanyPaymentProfile::create(['company_id' => $this->company->id, 'provider_key' => 'stripe', 'method_key' => 'card',
            'provider_payment_method_id' => 'pm_fake_830', 'label' => 'Fake', 'is_default' => true]);
        $test = $this;
        $this->app->instance(StripePaymentAdapter::class, new class($test) extends StripePaymentAdapter
        {
            public function __construct(private $t) {}

            public function chargeInvoiceWithPaymentMethod(Invoice $invoice, string $paymentMethodId, ?int $amount = null): array
            {
                return $this->t->fakeCharge($invoice, $paymentMethodId);
            }
        });
    }

    public function fakeCharge(Invoice $invoice, string $pm): array
    {
        $this->calls[] = "{$invoice->id}:{$pm}";
        if ($this->duringCharge) {
            ($this->duringCharge)($invoice);
        }

        return match ($this->providerMode) {
            'throw' => throw new \RuntimeException('provider timeout (simulated)'),
            'succeed' => ['status' => 'succeeded', 'provider_payment_id' => 'pi_fake'],
            default => ['status' => 'failed', 'error' => 'card_declined (simulated)'],
        };
    }

    /** Overdue invoice, first retry due 2026-03-11 00:00. */
    private function overdue(): Invoice
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($invoice);
        $invoice->update(['due_at' => Carbon::parse('2026-03-05')]);
        DunningEngine::processOverdueInvoices();
        $invoice->refresh();
        $this->assertSame(['overdue', 0, '2026-03-11'], [$invoice->status, $invoice->retry_count, $invoice->next_retry_at->toDateString()]);

        return $invoice;
    }

    private function state(Invoice $invoice): array
    {
        $i = Invoice::withoutGlobalScopes()->find($invoice->id);

        return [$i->status, $i->retry_count, $i->next_retry_at?->toDateString()];
    }

    private function job(Invoice $invoice): void
    {
        (new ProcessDunningBatchJob(collect([$invoice->id])))->handle();
    }

    public function test_a_due_attempt_runs_once_and_follows_the_policy(): void
    {
        $invoice = $this->overdue();
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $this->job($invoice);
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice));
    }

    public function test_a_premature_job_does_nothing(): void
    {
        $invoice = $this->overdue();
        $this->withProvider('fail');
        Carbon::setTestNow(Carbon::parse('2026-03-10 23:59'));
        $this->job($invoice);
        $this->assertSame(['overdue', 0, '2026-03-11'], $this->state($invoice));
        $this->assertSame([], $this->calls, 'no provider call before the due date');
    }

    public function test_a_late_and_redelivered_job_makes_no_extra_attempt(): void
    {
        $invoice = $this->overdue();
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        DunningEngine::processOverdueInvoices(); // the scheduled scan ran first
        Carbon::setTestNow(Carbon::parse('2026-03-12 09:00')); // the job is consumed late, then twice
        $this->job($invoice);
        $this->job($invoice);
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice));
        $this->assertSame('active', $this->company->fresh()->status);
    }

    public function test_a_concurrent_worker_holding_the_claim_makes_the_other_skip(): void
    {
        $invoice = $this->overdue();
        $this->withProvider('fail');
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $other = Cache::lock("billing:dunning:invoice:{$invoice->id}", 600);
        $this->assertTrue($other->get(), 'another worker holds the claim');
        $this->job($invoice);
        $this->assertSame(DunningEngine::processOverdueInvoices()['retried'], 0);
        $this->assertSame(['overdue', 0, '2026-03-11'], $this->state($invoice));
        $this->assertSame([], $this->calls, 'no provider call while claimed elsewhere');
        $other->release();
        $this->job($invoice);
        $this->job($invoice);
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice));
        $this->assertCount(1, $this->calls);
    }

    /** Lock expired mid-attempt : the other worker recorded the attempt first ; this one records nothing. */
    public function test_an_attempt_already_counted_by_a_concurrent_worker_is_not_counted_twice(): void
    {
        $invoice = $this->overdue();
        $this->withProvider('fail');
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $this->duringCharge = function (Invoice $i) {
            Invoice::withoutGlobalScopes()->whereKey($i->id)->update(['retry_count' => 1, 'next_retry_at' => '2026-03-14 01:00:00']);
            $this->duringCharge = null;
        };
        $this->assertSame('skipped', DunningEngine::retryIfDue($invoice));
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice), 'counted once, by the other worker');
    }

    public function test_an_invoice_paid_cancelled_or_uncollectible_between_attempts_is_never_retried(): void
    {
        foreach ([['status' => 'paid', 'paid_at' => now(), 'amount_due' => 0], ['status' => 'void', 'voided_at' => now()], ['status' => 'uncollectible', 'next_retry_at' => null]] as $state) {
            $invoice = $this->overdue();
            $invoice->update($state);
            $before = $this->state($invoice);
            Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
            $this->assertSame('not_due', DunningEngine::retryIfDue($invoice));
            $this->job($invoice);
            $this->assertSame($before, $this->state($invoice), $state['status']);
            $invoice->update(['status' => 'void', 'voided_at' => now()]);
        }
    }

    public function test_a_paid_invoice_is_not_debited_twice_by_duplicate_jobs(): void
    {
        $invoice = $this->overdue();
        WalletLedger::credit($this->company, 5000, 'admin_adjustment', actorType: 'platform_user', actorId: 1);
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $this->job($invoice);
        $this->job($invoice);
        DunningEngine::processOverdueInvoices();
        $this->assertSame('paid', Invoice::withoutGlobalScopes()->find($invoice->id)->status);
        $this->assertSame(5000 - 2900, WalletLedger::balance($this->company));
    }

    public function test_a_provider_error_counts_one_failed_attempt_and_nothing_else(): void
    {
        $invoice = $this->overdue();
        $this->withProvider('throw');
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $ledger = LedgerEntry::count();
        $this->job($invoice);
        $this->job($invoice);
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice));
        $this->assertCount(1, $this->calls);
        $this->assertSame($ledger, LedgerEntry::count(), 'no ledger entry for a failed attempt');
    }

    public function test_an_accepted_charge_waits_for_the_webhook_without_a_new_charge(): void
    {
        $invoice = $this->overdue();
        $this->withProvider('succeed');
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $this->assertSame('provider_attempted', DunningEngine::retryIfDue($invoice));
        Carbon::setTestNow(Carbon::parse('2026-03-12 01:00')); // webhook not received yet ; daily scan + job
        DunningEngine::processOverdueInvoices();
        $this->job($invoice);
        $this->assertCount(1, $this->calls, 'no second charge before the next scheduled date');
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice));
    }

    /** Interrupted worker : the claim is lost, nothing was recorded — the redelivery makes exactly one attempt. */
    public function test_reexecution_after_an_interruption_records_exactly_one_attempt(): void
    {
        $invoice = $this->overdue();
        $this->withProvider('fail');
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $crashed = Cache::lock("billing:dunning:invoice:{$invoice->id}", 600);
        $crashed->get();
        $this->job($invoice); // redelivered while the dead worker still holds the claim
        $this->assertSame(['overdue', 0, '2026-03-11'], $this->state($invoice));
        Cache::lock("billing:dunning:invoice:{$invoice->id}")->forceRelease(); // claim expiry
        $this->job($invoice);
        $this->job($invoice);
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice));
        $this->assertCount(1, $this->calls);
    }

    /** Duplicates at every date never exhaust early : uncollectible + suspension only at the 3rd DUE attempt, one write-off. */
    public function test_no_artificial_accumulation_reaches_uncollectible_or_suspension(): void
    {
        $invoice = $this->overdue();
        foreach (['2026-03-11 01:00', '2026-03-12 01:00', '2026-03-13 01:00', '2026-03-14 01:00', '2026-03-15 01:00'] as $t) {
            Carbon::setTestNow(Carbon::parse($t));
            $this->job($invoice);
            $this->job($invoice);
            DunningEngine::processOverdueInvoices();
        }
        $this->assertSame(['overdue', 2, '2026-03-21'], $this->state($invoice));
        $this->assertSame('active', $this->company->fresh()->status, 'no suspension before the last due attempt');

        Carbon::setTestNow(Carbon::parse('2026-03-21 01:00'));
        $this->job($invoice);
        $this->job($invoice);
        DunningEngine::processOverdueInvoices();
        $this->assertSame(['uncollectible', 3, null], $this->state($invoice));
        $this->assertSame('suspended', $this->company->fresh()->status);
        // One write-off POSTING (double entry = several lines sharing one correlation id), never two.
        $this->assertLessThanOrEqual(1, LedgerEntry::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->where('entry_type', 'writeoff')->distinct()->count('correlation_id'));
    }

    public function test_a_suspended_company_follows_the_existing_rule(): void
    {
        $invoice = $this->overdue();
        $this->company->update(['status' => 'suspended']);
        Carbon::setTestNow(Carbon::parse('2026-03-11 01:00'));
        $this->job($invoice);
        $this->job($invoice);
        $this->assertSame(['overdue', 1, '2026-03-14'], $this->state($invoice));
        $this->assertSame('suspended', $this->company->fresh()->status);
    }
}
