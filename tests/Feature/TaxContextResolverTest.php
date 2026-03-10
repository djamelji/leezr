<?php

namespace Tests\Feature;

use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\VatCheck;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Modules\Core\Billing\DTOs\TaxContext;
use App\Modules\Core\Billing\Services\TaxContextResolver;
use App\Modules\Core\Billing\Services\VatValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-310: Tax context resolution tests.
 *
 * Validates the 5 cases: same country, B2B intra-EU, B2C intra-EU, extra-EU, VIES unavailable.
 */
class TaxContextResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();
        MarketRegistry::sync();
        FieldDefinitionCatalog::sync();

        // Ensure FR market is EU
        Market::where('key', 'FR')->update(['is_eu' => true]);

        // Ensure config points to FR as seller
        config(['billing.platform.market_key' => 'FR']);
    }

    private function createCompany(string $marketKey, ?string $vatNumber = null): Company
    {
        $company = Company::create([
            'name' => 'Test Co ' . $marketKey,
            'slug' => 'test-co-' . strtolower($marketKey) . '-' . uniqid(),
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => $marketKey,
            'jobdomain_key' => 'logistique',
        ]);

        if ($vatNumber) {
            $fieldDef = FieldDefinition::where('code', 'vat_number')
                ->whereNull('company_id')
                ->first();

            if ($fieldDef) {
                FieldValue::create([
                    'field_definition_id' => $fieldDef->id,
                    'model_type' => 'company',
                    'model_id' => $company->id,
                    'value' => $vatNumber,
                ]);
            }
        }

        return $company;
    }

    private function createEuMarket(string $key, string $name): void
    {
        Market::updateOrCreate(
            ['key' => $key],
            [
                'name' => $name,
                'currency' => 'EUR',
                'vat_rate_bps' => 1900,
                'locale' => 'de-DE',
                'timezone' => 'Europe/Berlin',
                'dial_code' => '+49',
                'is_active' => true,
                'is_default' => false,
                'is_eu' => true,
            ],
        );
    }

    // ─── Case 1: Same country ────────────────────────────────

    public function test_same_country_returns_standard_rate(): void
    {
        $company = $this->createCompany('FR');

        $context = TaxContextResolver::resolve($company);

        $this->assertInstanceOf(TaxContext::class, $context);
        $this->assertEquals(2000, $context->taxRateBps); // FR = 20%
        $this->assertNull($context->exemptionReason);
        $this->assertFalse($context->isExempt());
    }

    // ─── Case 2: B2B intra-EU with valid VAT ─────────────────

    public function test_b2b_intra_eu_with_valid_vat_returns_reverse_charge(): void
    {
        $this->createEuMarket('DE', 'Germany');

        // Pre-seed VIES cache as valid
        VatCheck::create([
            'vat_number' => '123456789',
            'country_code' => 'DE',
            'is_valid' => true,
            'name' => 'German GmbH',
            'address' => 'Berlin',
            'checked_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $company = $this->createCompany('DE', 'DE123456789');

        $context = TaxContextResolver::resolve($company);

        $this->assertEquals(0, $context->taxRateBps);
        $this->assertEquals('reverse_charge_intra_eu', $context->exemptionReason);
        $this->assertTrue($context->isExempt());
        $this->assertTrue($context->buyerIsEu);
        $this->assertTrue($context->sellerIsEu);
    }

    // ─── Case 3a: B2B intra-EU with invalid VAT ──────────────

    public function test_b2b_intra_eu_with_invalid_vat_returns_standard_rate(): void
    {
        $this->createEuMarket('DE', 'Germany');

        // Pre-seed VIES cache as invalid
        VatCheck::create([
            'vat_number' => '000000000',
            'country_code' => 'DE',
            'is_valid' => false,
            'checked_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $company = $this->createCompany('DE', 'DE000000000');

        $context = TaxContextResolver::resolve($company);

        $this->assertEquals(1900, $context->taxRateBps); // DE = 19%
        $this->assertNull($context->exemptionReason);
        $this->assertFalse($context->isExempt());
    }

    // ─── Case 3b: B2C intra-EU (no VAT) ──────────────────────

    public function test_b2c_intra_eu_returns_standard_rate(): void
    {
        $this->createEuMarket('DE', 'Germany');

        $company = $this->createCompany('DE'); // No VAT number

        $context = TaxContextResolver::resolve($company);

        $this->assertEquals(1900, $context->taxRateBps); // DE = 19%
        $this->assertNull($context->exemptionReason);
        $this->assertFalse($context->isExempt());
    }

    // ─── Case 4: Extra-EU ─────────────────────────────────────

    public function test_extra_eu_returns_zero_and_export(): void
    {
        // GB is already seeded as non-EU
        Market::where('key', 'GB')->update(['is_eu' => false]);

        $company = $this->createCompany('GB');

        $context = TaxContextResolver::resolve($company);

        $this->assertEquals(0, $context->taxRateBps);
        $this->assertEquals('export_extra_eu', $context->exemptionReason);
        $this->assertTrue($context->isExempt());
        $this->assertFalse($context->buyerIsEu);
    }

    // ─── Case 5: VIES unavailable fallback ────────────────────

    public function test_vies_unavailable_falls_back_gracefully(): void
    {
        $this->createEuMarket('DE', 'Germany');

        // Mock VIES as unavailable → fallback assumes valid → reverse charge
        VatValidationService::$testSoapOverride = fn () => null;

        $company = $this->createCompany('DE', 'DE999999999');

        $context = TaxContextResolver::resolve($company);

        // Should fallback to valid (reverse charge) because VIES is unreachable
        $this->assertEquals(0, $context->taxRateBps);
        $this->assertEquals('reverse_charge_intra_eu', $context->exemptionReason);

        VatValidationService::$testSoapOverride = null;
    }
}
