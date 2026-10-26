<?php

namespace Tests\Feature;

use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\TaxResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BILL-011 — the fiscal label fix is UI-only. This pins that the COMPUTATION for the three accepted
 * tax_mode values is unchanged: 'none' behaves exactly like 'exclusive' (tax added on top — it was
 * NEVER zero-tax), and 'inclusive' extracts. The relabel of 'none' ("Market-based taxation (legacy
 * mode)") changes presentation only; TaxResolver is untouched. Guards against a future accidental
 * 'none' => 0 that would silently zero next invoices.
 */
class TaxModePresentationInvariantTest extends TestCase
{
    use RefreshDatabase;

    private function setMode(string $mode): void
    {
        PlatformBillingPolicy::instance()->update(['tax_mode' => $mode]);
    }

    public function test_none_equals_exclusive_tax_is_added_not_zeroed(): void
    {
        $rate = 2000; // 20%
        $subtotal = 1000;

        $this->setMode('none');
        $noneTax = TaxResolver::compute($subtotal, $rate);

        $this->setMode('exclusive');
        $exclusiveTax = TaxResolver::compute($subtotal, $rate);

        $this->assertSame(200, $noneTax, "'none' must ADD 20% (not zero) — it is the default/exclusive behaviour");
        $this->assertSame($exclusiveTax, $noneTax, "'none' and 'exclusive' must compute identically");
    }

    public function test_inclusive_extracts_tax(): void
    {
        $this->setMode('inclusive');
        // 1000 TTC @ 20% → net 833, tax 167
        $this->assertSame(167, TaxResolver::compute(1000, 2000));
    }

    public function test_zero_rate_yields_zero_regardless_of_mode(): void
    {
        // The zero-tax path is RATE-driven (exemption / market=0), never the mode.
        foreach (['none', 'exclusive', 'inclusive'] as $mode) {
            $this->setMode($mode);
            $this->assertSame(0, TaxResolver::compute(1000, 0), "rate 0 → 0 tax for mode {$mode}");
        }
    }
}
