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
     * ADR-575 — PAID subscription in PROLONGED delay (5 months overdue): billing:renew must
     * DEFER (no retroactive cascade), create NO invoice, flag for manual review, across runs.
     */
    public function test_paid_prolonged_delay_is_deferred_not_billed(): void
    {
        $sub = $this->overdueSub($this->company('overdue-paid'), 'pro', 5);
        $frozenEnd = $sub->current_period_end->toDateString();

        Artisan::call('billing:renew'); // run 1
        Artisan::call('billing:renew'); // run 2
        Artisan::call('billing:renew'); // run 3

        $invoices = Invoice::where('subscription_id', $sub->id)->whereNotIn('status', ['void'])->count();
        $this->assertSame(0, $invoices, 'Prolonged delay must NOT create a retroactive invoice (no cascade).');

        $fresh = $sub->fresh();
        $this->assertSame($frozenEnd, $fresh->current_period_end->toDateString(), 'Period not advanced.');
        $this->assertTrue((bool) ($fresh->metadata['renewal_review_required'] ?? false),
            'Subscription must be flagged for manual review (traceable).');
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
