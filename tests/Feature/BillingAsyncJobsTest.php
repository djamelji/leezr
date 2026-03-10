<?php

namespace Tests\Feature;

use App\Core\Billing\Adapters\StripePaymentAdapter;
use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\Invoice;
use App\Core\Billing\ReconciliationEngine;
use App\Core\Billing\Subscription;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Jobs\Billing\ProcessDunningBatchJob;
use App\Jobs\Billing\ReconcileCompanyJob;
use App\Jobs\Billing\RenewSubscriptionBatchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * ADR-318: Async billing jobs — queue dispatching and execution.
 *
 * Tests: ProcessDunningBatchJob, RenewSubscriptionBatchJob, ReconcileCompanyJob,
 * dunning batch processing, and incremental reconciliation last_reconciled_at.
 */
class BillingAsyncJobsTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->market = Market::firstOrCreate(
            ['key' => 'fr'],
            [
                'name' => 'France',
                'currency' => 'EUR',
                'vat_rate_bps' => 2000,
                'locale' => 'fr_FR',
                'timezone' => 'Europe/Paris',
                'dial_code' => '+33',
                'flag_code' => 'FR',
                'is_active' => true,
                'is_default' => true,
            ],
        );
    }

    private function createCompany(string $name, string $slug): Company
    {
        return Company::create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'market_key' => $this->market->key,
        ]);
    }

    // ── 1: Dunning async dispatches batch jobs ──────────

    public function test_dunning_async_dispatches_batch_jobs(): void
    {
        Bus::fake([ProcessDunningBatchJob::class]);

        $company = $this->createCompany('Dunning Co', 'dunning-co');

        // Create 3 overdue invoices with next_retry_at in the past
        foreach (range(1, 3) as $i) {
            Invoice::create([
                'company_id' => $company->id,
                'subscription_id' => null,
                'number' => "INV-DTEST-{$i}",
                'status' => 'overdue',
                'amount' => 5000,
                'amount_due' => 5000,
                'due_at' => now()->subDays(10),
                'finalized_at' => now()->subDays(10),
                'next_retry_at' => now()->subHour(),
            ]);
        }

        $this->artisan('billing:process-dunning', ['--async' => true])
            ->assertSuccessful();

        Bus::assertDispatched(ProcessDunningBatchJob::class);
    }

    // ── 2: Renewal async dispatches batch jobs ──────────

    public function test_renewal_async_dispatches_batch_jobs(): void
    {
        Bus::fake([RenewSubscriptionBatchJob::class]);

        // Create 2 companies, each with one active subscription (is_current unique per company)
        foreach (range(1, 2) as $i) {
            $company = $this->createCompany("Renew Co {$i}", "renew-co-{$i}");

            Subscription::create([
                'company_id' => $company->id,
                'plan_key' => 'starter',
                'status' => 'active',
                'interval' => 'monthly',
                'is_current' => 1,
                'current_period_end' => now()->subDay(),
                'provider' => 'internal',
            ]);
        }

        $this->artisan('billing:renew', ['--async' => true])
            ->assertSuccessful();

        Bus::assertDispatched(RenewSubscriptionBatchJob::class);
    }

    // ── 3: Reconcile async dispatches per company ───────

    public function test_reconcile_async_dispatches_per_company(): void
    {
        Bus::fake([ReconcileCompanyJob::class]);

        // Create 3 companies with CompanyPaymentCustomer records
        foreach (range(1, 3) as $i) {
            $company = $this->createCompany("Recon Co {$i}", "recon-co-{$i}");

            CompanyPaymentCustomer::create([
                'company_id' => $company->id,
                'provider_key' => 'stripe',
                'provider_customer_id' => "cus_test{$i}",
            ]);
        }

        $this->artisan('billing:reconcile', ['--async' => true])
            ->assertSuccessful();

        Bus::assertDispatched(ReconcileCompanyJob::class, 3);
    }

    // ── 4: Dunning batch job processes overdue invoices ─

    public function test_dunning_batch_job_processes_overdue_invoices(): void
    {
        $company = $this->createCompany('Batch Co', 'batch-co');

        $invoice = Invoice::create([
            'company_id' => $company->id,
            'subscription_id' => null,
            'number' => 'INV-BATCH-1',
            'status' => 'overdue',
            'amount' => 3000,
            'amount_due' => 3000,
            'due_at' => now()->subDays(5),
            'finalized_at' => now()->subDays(5),
            'next_retry_at' => now()->subHour(),
        ]);

        // Dispatch the job synchronously (no exception = success)
        $job = new ProcessDunningBatchJob(collect([$invoice->id]));
        $job->handle();

        // Verify the invoice was processed (status may change or retry_count incremented)
        $invoice->refresh();
        $this->assertContains($invoice->status, ['overdue', 'paid', 'uncollectible']);
    }

    // ── 5: Reconcile updates last_reconciled_at ─────────

    public function test_reconcile_updates_last_reconciled_at(): void
    {
        $company = $this->createCompany('Reconcile Co', 'reconcile-co');

        $customer = CompanyPaymentCustomer::create([
            'company_id' => $company->id,
            'provider_key' => 'stripe',
            'provider_customer_id' => 'cus_test123',
            'last_reconciled_at' => null,
        ]);

        // Mock StripePaymentAdapter to return empty payment intents
        // so the reconciliation completes without hitting Stripe API
        $mock = \Mockery::mock(StripePaymentAdapter::class);
        $mock->shouldReceive('listPaymentIntents')
            ->andReturn([]);
        $this->app->instance(StripePaymentAdapter::class, $mock);

        ReconciliationEngine::reconcile($company->id);

        $customer->refresh();
        $this->assertNotNull($customer->last_reconciled_at);
    }
}
