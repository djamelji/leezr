<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ANO-001 finalization: regularize active-like subscriptions wrongly left
 * is_current=NULL — safely, with no billing side effects, respecting the
 * one-current-per-company constraint.
 */
class RegularizeCurrentSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug): Company
    {
        return Company::create(['name' => $slug, 'slug' => $slug, 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
    }

    private function sub(Company $c, array $attrs): Subscription
    {
        return Subscription::create(array_merge([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'provider' => 'internal',
            'current_period_start' => '2026-05-01', 'current_period_end' => '2026-05-31',
        ], $attrs));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $sub = $this->sub($this->company('a'), ['status' => 'active', 'is_current' => null]);

        Artisan::call('billing:regularize-current-subscriptions', ['--dry-run' => true]);

        $this->assertNull($sub->fresh()->is_current);
    }

    public function test_regularizes_active_sub_without_is_current(): void
    {
        $sub = $this->sub($this->company('b'), ['status' => 'active', 'is_current' => null]);
        $invoicesBefore = Invoice::count();

        Artisan::call('billing:regularize-current-subscriptions');

        $this->assertSame(1, (int) $sub->fresh()->is_current);
        $this->assertSame($invoicesBefore, Invoice::count(), 'Regularization must not create any invoice (no billing side effect).');
    }

    public function test_skips_conflict_when_company_already_has_a_current(): void
    {
        $c = $this->company('c');
        $this->sub($c, ['status' => 'active', 'is_current' => 1]);               // existing current
        $orphan = $this->sub($c, ['status' => 'past_due', 'is_current' => null]); // orphan

        Artisan::call('billing:regularize-current-subscriptions');

        // Orphan left as-is (flipping it would violate the unique constraint).
        $this->assertNull($orphan->fresh()->is_current);
    }

    public function test_is_idempotent(): void
    {
        $sub = $this->sub($this->company('d'), ['status' => 'trialing', 'is_current' => null, 'trial_ends_at' => now()->addDays(5)]);

        Artisan::call('billing:regularize-current-subscriptions');
        Artisan::call('billing:regularize-current-subscriptions');

        $this->assertSame(1, (int) $sub->fresh()->is_current);
        $this->assertSame(1, Subscription::where('company_id', $sub->company_id)->where('is_current', 1)->count());
    }
}
