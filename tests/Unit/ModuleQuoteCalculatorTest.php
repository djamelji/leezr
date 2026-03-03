<?php

namespace Tests\Unit;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Modules\Pricing\Quote;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ModuleQuoteCalculator (ADR-116).
 *
 * Uses real manifests with logistics modules.
 * logistics_tracking requires logistics_shipments.
 */
class ModuleQuoteCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->company = Company::create([
            'name' => 'Quote Co',
            'slug' => 'quote-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        // Assign logistique jobdomain — updateOrCreate to override seeded defaults
        $jobdomain = Jobdomain::updateOrCreate(
            ['key' => 'logistique'],
            [
                'label' => 'Logistique',
                'is_active' => true,
                'default_modules' => [
                    'logistics_shipments',
                    'logistics_tracking',
                    'logistics_fleet',
                    'logistics_analytics',
                ],
                'allow_custom_fields' => true,
            ],
        );
        $this->company->jobdomains()->sync([$jobdomain->id]);
    }

    // ─── Basic quoting ──────────────────────────────────────

    public function test_empty_selection_returns_zero(): void
    {
        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, []);

        $this->assertEquals(0, $quote->total);
        $this->assertEmpty($quote->lines);
        $this->assertEmpty($quote->included);
    }

    public function test_single_module_no_requires_included_pricing(): void
    {
        // logistics_shipments with default pricing (not addon) → 0 charge
        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_shipments']);

        $this->assertEquals(0, $quote->total);
        $this->assertEmpty($quote->lines); // Not addon → no line
        $this->assertEmpty($quote->included);
    }

    public function test_single_module_addon_flat_pricing(): void
    {
        // Set tracking to addon with flat pricing
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 15],
            ]);

        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_tracking']);

        $this->assertEquals(1500, $quote->total); // 15€ = 1500 cents
        $this->assertCount(1, $quote->lines);
        $this->assertEquals('logistics_tracking', $quote->lines[0]->key);
        $this->assertEquals(1500, $quote->lines[0]->amount);
    }

    public function test_single_module_addon_plan_flat_pricing(): void
    {
        // Set tracking to addon with plan_flat pricing
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'plan_flat',
                'pricing_params' => ['starter' => 10, 'pro' => 20, 'business' => 30],
            ]);

        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_tracking']);

        // Company plan is 'pro' → 20€ = 2000 cents
        $this->assertEquals(2000, $quote->total);
        $this->assertCount(1, $quote->lines);
        $this->assertEquals(2000, $quote->lines[0]->amount);
    }

    // ─── Requires handling ──────────────────────────────────

    public function test_module_with_requires_lists_requires_in_included(): void
    {
        // tracking requires shipments
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);

        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_tracking']);

        $this->assertCount(1, $quote->included);
        $this->assertEquals('logistics_shipments', $quote->included[0]->key);
    }

    public function test_requires_not_billed_even_if_addon(): void
    {
        // This scenario shouldn't happen due to pricing invariants,
        // but the calculator still handles it correctly: only selected keys are billed
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);

        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_tracking']);

        // Only tracking is billed, not shipments
        $this->assertEquals(1000, $quote->total);
        $billableKeys = array_map(fn ($l) => $l->key, $quote->lines);
        $this->assertNotContains('logistics_shipments', $billableKeys);
    }

    // ─── Multi-module selection ─────────────────────────────

    public function test_multi_module_selection(): void
    {
        $this->company->update(['plan_key' => 'business']);

        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);

        PlatformModule::where('key', 'logistics_fleet')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 25],
            ]);

        $quote = ModuleQuoteCalculator::quoteForCompany(
            $this->company,
            ['logistics_tracking', 'logistics_fleet'],
        );

        $this->assertEquals(3500, $quote->total); // 1000 + 2500
        $this->assertCount(2, $quote->lines);
    }

    public function test_overlapping_requires_not_duplicated_in_included(): void
    {
        $this->company->update(['plan_key' => 'business']);

        // Both tracking and fleet require shipments
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);
        PlatformModule::where('key', 'logistics_fleet')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 25],
            ]);

        $quote = ModuleQuoteCalculator::quoteForCompany(
            $this->company,
            ['logistics_tracking', 'logistics_fleet'],
        );

        // logistics_shipments should appear once in included
        $includedKeys = array_map(fn ($i) => $i->key, $quote->included);
        $this->assertCount(1, array_filter($includedKeys, fn ($k) => $k === 'logistics_shipments'));
    }

    // ─── Determinism ────────────────────────────────────────

    public function test_deterministic_output(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);

        $keys = ['logistics_tracking', 'logistics_shipments'];

        $quote1 = ModuleQuoteCalculator::quoteForCompany($this->company, $keys);
        $quote2 = ModuleQuoteCalculator::quoteForCompany($this->company, $keys);

        $this->assertSame($quote1->toArray(), $quote2->toArray());
    }

    public function test_key_order_does_not_affect_output(): void
    {
        $this->company->update(['plan_key' => 'business']);

        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);
        PlatformModule::where('key', 'logistics_fleet')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 25],
            ]);

        $quote1 = ModuleQuoteCalculator::quoteForCompany(
            $this->company,
            ['logistics_tracking', 'logistics_fleet'],
        );
        $quote2 = ModuleQuoteCalculator::quoteForCompany(
            $this->company,
            ['logistics_fleet', 'logistics_tracking'],
        );

        $this->assertSame($quote1->toArray(), $quote2->toArray());
    }

    // ─── Validation ─────────────────────────────────────────

    public function test_invalid_module_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ModuleQuoteCalculator::quoteForCompany($this->company, ['nonexistent_module']);
    }

    public function test_globally_disabled_module_throws(): void
    {
        PlatformModule::where('key', 'logistics_shipments')
            ->update(['is_enabled_globally' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not available globally/');

        ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_shipments']);
    }

    public function test_plan_mismatch_throws_validation_error(): void
    {
        // logistics_fleet requires minPlan='pro', company is starter
        $this->company->update(['plan_key' => 'starter']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not available for this company/');

        ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_fleet']);
    }

    // ─── Currency ───────────────────────────────────────────

    public function test_quote_includes_currency(): void
    {
        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, []);

        $this->assertNotEmpty($quote->currency);
    }

    // ─── Quote DTO ──────────────────────────────────────────

    public function test_quote_to_array(): void
    {
        PlatformModule::where('key', 'logistics_tracking')
            ->update([
                'pricing_mode' => 'addon',
                'pricing_model' => 'flat',
                'pricing_params' => ['price_monthly' => 10],
            ]);

        $quote = ModuleQuoteCalculator::quoteForCompany($this->company, ['logistics_tracking']);

        $array = $quote->toArray();

        $this->assertArrayHasKey('total', $array);
        $this->assertArrayHasKey('currency', $array);
        $this->assertArrayHasKey('lines', $array);
        $this->assertArrayHasKey('included', $array);

        $this->assertArrayHasKey('key', $array['lines'][0]);
        $this->assertArrayHasKey('title', $array['lines'][0]);
        $this->assertArrayHasKey('amount', $array['lines'][0]);
    }
}
