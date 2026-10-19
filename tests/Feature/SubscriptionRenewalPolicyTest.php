<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ADR-575 — renewal policy: no automatic cascade billing of overdue paid periods.
 * Classifies normal / technical delay / prolonged delay / suspension / reactivation / free / trial,
 * and guards idempotence (no duplicate invoice) across repeated scheduler runs.
 * All on the local isolated test DB (RefreshDatabase). Internal provider (no real gateway).
 */
class SubscriptionRenewalPolicyTest extends TestCase
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

    private function sub(Company $c, array $attrs = []): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly',
            'status' => 'active', 'provider' => 'internal', 'is_current' => 1,
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->subDay(),
        ], $attrs));
    }

    private function invoiceCount(Subscription $s): int
    {
        return Invoice::where('subscription_id', $s->id)->whereNotIn('status', ['void'])->count();
    }

    // 1) Normal renewal: period just ended (within the first due period) → bills exactly one period.
    public function test_normal_renewal_bills_one_period(): void
    {
        $s = $this->sub($this->company('normal'), ['current_period_end' => now()->subDay()]);
        Artisan::call('billing:renew');
        $this->assertSame(1, $this->invoiceCount($s), 'Normal renewal bills exactly one due period.');
    }

    // 2) Technical delay: cron late by a few days but still within the first due period → one invoice.
    public function test_technical_delay_still_bills_one_period(): void
    {
        $s = $this->sub($this->company('tech-delay'), ['current_period_end' => now()->subDays(10)]);
        Artisan::call('billing:renew');
        $this->assertSame(1, $this->invoiceCount($s), 'A few days late is a technical delay → one invoice.');
    }

    // 3) Prolonged delay with a provider available: still deferred (decision BEFORE provider resolution).
    public function test_prolonged_delay_is_deferred_regardless_of_provider(): void
    {
        $s = $this->sub($this->company('prolonged'), [
            'provider' => 'stripe',
            'current_period_end' => now()->subMonths(5),
        ]);
        Artisan::call('billing:renew');
        $this->assertSame(0, $this->invoiceCount($s), 'Prolonged delay never cascades, even with a provider.');
        $this->assertTrue((bool) ($s->fresh()->metadata['renewal_review_required'] ?? false));
    }

    // 4) Suspension: a cancelled/suspended subscription is not eligible → no automatic billing.
    public function test_suspended_subscription_is_not_billed(): void
    {
        $s = $this->sub($this->company('suspended'), ['status' => 'cancelled', 'is_current' => null]);
        Artisan::call('billing:renew');
        $this->assertSame(0, $this->invoiceCount($s), 'A suspended/cancelled subscription is never auto-billed.');
    }

    // 5) Free plan: full catch-up to a future period, no invoice.
    public function test_free_plan_catches_up_without_invoice(): void
    {
        $s = $this->sub($this->company('free'), ['plan_key' => 'starter', 'current_period_end' => now()->subMonths(5)]);
        Artisan::call('billing:renew');
        $this->assertTrue($s->fresh()->current_period_end->isFuture(), 'Free plan caught up to the future.');
        $this->assertSame(0, $this->invoiceCount($s), 'Free plan creates no invoice.');
    }

    // 6) Trial just ended → eligible; a paid trialing sub within its first due period bills once.
    public function test_expired_trial_within_first_period_bills_once(): void
    {
        $s = $this->sub($this->company('trial'), ['status' => 'trialing', 'current_period_end' => now()->subDay()]);
        Artisan::call('billing:renew');
        $this->assertSame(1, $this->invoiceCount($s), 'An expired trial within the first due period bills once.');
    }

    // 7) Several scheduler runs on a normal renewal → no duplicate invoice (idempotent).
    public function test_repeated_runs_do_not_duplicate_invoice(): void
    {
        $s = $this->sub($this->company('idem'), ['current_period_end' => now()->subDay()]);
        Artisan::call('billing:renew');
        Artisan::call('billing:renew');
        Artisan::call('billing:renew');
        $this->assertSame(1, $this->invoiceCount($s), 'Repeated runs must not duplicate the renewal invoice.');
    }

    // 8) Prolonged delay across several runs → stays deferred, never creates an invoice.
    public function test_prolonged_delay_stays_deferred_across_runs(): void
    {
        $s = $this->sub($this->company('prolonged-multi'), ['current_period_end' => now()->subMonths(4)]);
        Artisan::call('billing:renew');
        Artisan::call('billing:renew');
        $this->assertSame(0, $this->invoiceCount($s), 'Prolonged delay never accumulates invoices across runs.');
    }

    // 9) Internal unpaid within first due period: one open invoice, no duplicate charge/invoice on re-run.
    public function test_internal_unpaid_first_period_single_open_invoice(): void
    {
        $s = $this->sub($this->company('internal-open'), ['current_period_end' => now()->subDay()]);
        Artisan::call('billing:renew');
        Artisan::call('billing:renew');
        $this->assertSame(1, $this->invoiceCount($s), 'Internal unpaid renewal leaves a single open invoice (no duplicate).');
        $inv = Invoice::where('subscription_id', $s->id)->first();
        $this->assertNotSame('paid', $inv->status, 'Internal with no payment method is not auto-paid.');
    }
}
