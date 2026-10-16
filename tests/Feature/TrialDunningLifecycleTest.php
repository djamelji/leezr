<?php

namespace Tests\Feature;

use App\Core\Billing\Dunning\DunningTransitioner;
use App\Core\Billing\Invoice;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Trial ↔ dunning lifecycle (audit 2026-10, T3/T5).
 *
 * Coherent model:
 *  - FREE trial (no collectible invoice) → expire-trials ends it (trialing → expired).
 *  - PAID trial with an unpaid conversion invoice → dunning (trialing → past_due →
 *    retries → suspend/cancel); never silently expired (no orphan invoice).
 *  - Unpaid invoice on an active subscription → existing dunning path.
 */
class TrialDunningLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->company = Company::create([
            'name' => 'Co',
            'slug' => 'co',
            'jobdomain_key' => 'logistique',
        ]);
    }

    private function sub(array $attrs, ?Company $company = null): Subscription
    {
        $company ??= $this->company;

        return Subscription::create(array_merge([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'provider' => 'internal',
        ], $attrs));
    }

    private function openInvoice(Subscription $sub): Invoice
    {
        return Invoice::create([
            'company_id' => $sub->company_id,
            'subscription_id' => $sub->id,
            'currency' => 'EUR',
            'status' => 'open',
            'amount' => 1000,
            'subtotal' => 1000,
            'tax_amount' => 0,
            'tax_rate_bps' => 0,
            'wallet_credit_applied' => 0,
            'amount_due' => 1000,
        ]);
    }

    public function test_paid_trial_with_overdue_invoice_enters_dunning(): void
    {
        $sub = $this->sub(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->subDay()]);
        $invoice = $this->openInvoice($sub);

        DunningTransitioner::markOverdue($invoice, PlatformBillingPolicy::instance());

        $this->assertSame('past_due', $sub->fresh()->status, 'Paid trial with overdue invoice must enter dunning.');
        $this->assertSame('overdue', $invoice->fresh()->status);
    }

    public function test_expire_trials_ends_free_trial_but_skips_paid_trial_in_collection(): void
    {
        // Free trial: no collectible invoice.
        $free = $this->sub(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->subDay()]);

        // Paid trial (distinct company — one is_current=1 per company): conversion invoice still open.
        $paidCo = Company::create(['name' => 'Paid', 'slug' => 'paid', 'jobdomain_key' => 'logistique']);
        $paid = $this->sub(['status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->subDay()], $paidCo);
        $this->openInvoice($paid);

        Artisan::call('billing:expire-trials');

        $free = $free->fresh();
        $this->assertSame('expired', $free->status, 'Free trial must be expired.');
        $this->assertNull($free->is_current);

        $this->assertSame('trialing', $paid->fresh()->status, 'Paid trial with an open invoice must NOT be expired — dunning owns it.');
    }

    public function test_active_subscription_renewal_failure_still_enters_dunning(): void
    {
        // Regression guard: the active → past_due path must keep working.
        $sub = $this->sub(['status' => 'active', 'is_current' => 1, 'current_period_end' => now()->subDay()]);
        $invoice = $this->openInvoice($sub);

        DunningTransitioner::markOverdue($invoice, PlatformBillingPolicy::instance());

        $this->assertSame('past_due', $sub->fresh()->status);
    }
}
