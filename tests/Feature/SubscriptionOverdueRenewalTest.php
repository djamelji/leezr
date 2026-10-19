<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ANO-001 — renewal cycle for an OVERDUE current subscription (period_end months in the past,
 * e.g. the PROD sub #1 regularized to is_current=1 while its period ended months ago).
 *
 * These tests DOCUMENT the current behaviour (local isolated DB). They characterize:
 *   - how far a single billing:renew run advances a free/unpaid subscription,
 *   - whether N months of delay create N invoices, one, or a freeze.
 */
class SubscriptionOverdueRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        \App\Core\Plans\PlanRegistry::sync();
    }

    private function company(string $slug): Company
    {
        return Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
    }

    private function overdueSub(Company $c, string $plan, int $monthsPast): Subscription
    {
        return Subscription::create([
            'company_id' => $c->id, 'plan_key' => $plan, 'interval' => 'monthly',
            'status' => 'active', 'provider' => 'internal', 'is_current' => 1,
            'current_period_start' => now()->subMonths($monthsPast + 1),
            'current_period_end' => now()->subMonths($monthsPast),
        ]);
    }

    /**
     * PAID internal subscription, no payment method, 5 months overdue.
     * Current behaviour: one renewal invoice for the FIRST overdue period, then idempotent;
     * the period stays frozen (extendPeriod only runs on payment success) → NO invoice pile-up.
     */
    public function test_paid_internal_overdue_creates_single_invoice_and_freezes_period(): void
    {
        $sub = $this->overdueSub($this->company('overdue-paid'), 'pro', 5);
        $frozenEnd = $sub->current_period_end->toDateString();

        Artisan::call('billing:renew'); // run 1
        Artisan::call('billing:renew'); // run 2
        Artisan::call('billing:renew'); // run 3

        $invoices = Invoice::where('subscription_id', $sub->id)->whereNotIn('status', ['void'])->count();
        $this->assertSame(1, $invoices, 'Unpaid internal renewal must not pile up invoices across runs (idempotent).');

        // Period stays frozen at the first overdue end (no auto-payment → no advance).
        $this->assertSame($frozenEnd, $sub->fresh()->current_period_end->toDateString(),
            'Without payment the period stays frozen (not advanced).');
    }

    /**
     * FREE plan, 5 months overdue. ANO-001 fix: a single run catches up fully to a FUTURE period
     * (no money → nothing to bill retroactively). No invoice is ever created for a free plan.
     */
    public function test_free_overdue_catches_up_fully_in_one_run(): void
    {
        $sub = $this->overdueSub($this->company('overdue-free'), 'starter', 5); // starter = price 0

        Artisan::call('billing:renew'); // single run

        $afterEnd = $sub->fresh()->current_period_end;
        $this->assertTrue($afterEnd->isFuture(),
            'A free overdue subscription must be fully caught up to a future period in one run.');
        $this->assertSame(0, Invoice::where('subscription_id', $sub->id)->count(),
            'A free plan renewal creates no invoice.');
    }
}
