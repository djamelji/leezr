<?php

namespace Tests\Feature;

use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\VatCheck;
use App\Core\Billing\WalletLedger;
use App\Core\Fields\FieldDefinition;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Fields\FieldValue;
use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-310: Invoice tax exemption integration tests.
 */
class InvoiceTaxExemptionTest extends TestCase
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

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        config(['billing.platform.market_key' => 'FR']);
        Market::where('key', 'FR')->update(['is_eu' => true]);
    }

    public function test_invoice_created_for_intra_eu_company_has_reverse_charge(): void
    {
        // Create DE market (EU)
        Market::updateOrCreate(
            ['key' => 'DE'],
            [
                'name' => 'Germany',
                'currency' => 'EUR',
                'vat_rate_bps' => 1900,
                'locale' => 'de-DE',
                'timezone' => 'Europe/Berlin',
                'dial_code' => '+49',
                'is_active' => true,
                'is_eu' => true,
            ],
        );

        // Pre-seed valid VAT in VIES cache
        VatCheck::create([
            'vat_number' => '123456789',
            'country_code' => 'DE',
            'is_valid' => true,
            'name' => 'German GmbH',
            'checked_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $company = Company::create([
            'name' => 'German Test Co',
            'slug' => 'german-test-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => 'DE',
            'jobdomain_key' => 'logistique',
        ]);

        // Set VAT number via dynamic fields
        $fieldDef = FieldDefinition::where('code', 'vat_number')
            ->whereNull('company_id')
            ->first();

        FieldValue::create([
            'field_definition_id' => $fieldDef->id,
            'model_type' => 'company',
            'model_id' => $company->id,
            'value' => 'DE123456789',
        ]);

        // Ensure wallet exists
        WalletLedger::ensureWallet($company);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'is_current' => true,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'amount' => 2900,
            'currency' => 'EUR',
        ]);

        $draft = InvoiceIssuer::createDraft($company, $subscription->id);

        $this->assertEquals(0, $draft->tax_rate_bps);
        $this->assertEquals('reverse_charge_intra_eu', $draft->tax_exemption_reason);
    }

    public function test_invoice_for_same_country_has_no_exemption(): void
    {
        $company = Company::create([
            'name' => 'French Test Co',
            'slug' => 'french-test-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => 'FR',
            'jobdomain_key' => 'logistique',
        ]);

        WalletLedger::ensureWallet($company);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'is_current' => true,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'amount' => 2900,
            'currency' => 'EUR',
        ]);

        $draft = InvoiceIssuer::createDraft($company, $subscription->id);

        $this->assertEquals(2000, $draft->tax_rate_bps); // FR = 20%
        $this->assertNull($draft->tax_exemption_reason);
    }

    public function test_invoice_for_extra_eu_has_export_exemption(): void
    {
        Market::where('key', 'GB')->update(['is_eu' => false]);

        $company = Company::create([
            'name' => 'UK Test Co',
            'slug' => 'uk-test-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => 'GB',
            'jobdomain_key' => 'logistique',
        ]);

        WalletLedger::ensureWallet($company);

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'is_current' => true,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'amount' => 2900,
            'currency' => 'EUR',
        ]);

        $draft = InvoiceIssuer::createDraft($company, $subscription->id);

        $this->assertEquals(0, $draft->tax_rate_bps);
        $this->assertEquals('export_extra_eu', $draft->tax_exemption_reason);
    }
}
