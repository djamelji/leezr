<?php

namespace Tests\Feature;

use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Billing\WalletLedger;
use App\Core\Markets\Market;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\Pricing\ModuleQuoteCalculator;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-235: Market Based Billing Currency.
 *
 * Validates that billing currency is derived from company.market.currency,
 * not from hardcoded 'EUR' or config('app.currency').
 */
class BillingCurrencyMarketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        ModuleRegistry::sync();
        PlanRegistry::sync();

        PlatformPaymentModule::firstOrCreate(
            ['provider_key' => 'stripe'],
            [
                'name' => 'Stripe',
                'is_installed' => true,
                'is_active' => true,
                'health_status' => 'healthy',
            ],
        );
    }

    private function createCompanyInMarket(string $marketKey, string $name): Company
    {
        $owner = User::factory()->create();

        $company = Company::create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => $marketKey,
            'jobdomain_key' => 'logistique',
        ]);

        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return $company;
    }

    // ── 1: FR company → wallet currency = EUR ───────────

    public function test_fr_company_wallet_created_with_eur(): void
    {
        $company = $this->createCompanyInMarket('FR', 'French Co');

        $wallet = WalletLedger::ensureWallet($company);

        $this->assertEquals('EUR', $wallet->currency);
    }

    // ── 2: GB company → wallet currency = GBP ──────────

    public function test_gb_company_wallet_created_with_gbp(): void
    {
        $company = $this->createCompanyInMarket('GB', 'British Co');

        $wallet = WalletLedger::ensureWallet($company);

        $this->assertEquals('GBP', $wallet->currency);
    }

    // ── 3: Existing wallet currency is NOT changed ──────

    public function test_existing_wallet_currency_not_changed(): void
    {
        $company = $this->createCompanyInMarket('FR', 'Stable Co');

        // Create wallet with EUR
        $wallet = WalletLedger::ensureWallet($company);
        $this->assertEquals('EUR', $wallet->currency);

        // Change market to GB
        $company->update(['market_key' => 'GB']);
        $company->refresh();
        $company->unsetRelation('market');

        // ensureWallet should return the EXISTING wallet (still EUR)
        $wallet2 = WalletLedger::ensureWallet($company);
        $this->assertEquals('EUR', $wallet2->currency);
        $this->assertEquals($wallet->id, $wallet2->id);
    }

    // ── 4: Module quote uses wallet currency ────────────

    public function test_module_quote_uses_wallet_currency(): void
    {
        $company = $this->createCompanyInMarket('GB', 'Quote Co');

        // Empty quote still returns correct currency
        $quote = ModuleQuoteCalculator::quoteForCompany($company, []);

        $this->assertEquals('GBP', $quote->currency);
    }

    // ── 5: Invoice uses wallet currency ─────────────────

    public function test_invoice_uses_wallet_currency(): void
    {
        $company = $this->createCompanyInMarket('GB', 'Invoice Co');

        $sub = Subscription::create([
            'company_id' => $company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $invoice = InvoiceIssuer::createDraft($company, $sub->id);

        $this->assertEquals('GBP', $invoice->currency);
    }

    // ── 6: Addon subscription uses wallet currency ──────

    public function test_addon_subscription_uses_wallet_currency(): void
    {
        $company = $this->createCompanyInMarket('GB', 'Addon Co');
        $wallet = WalletLedger::ensureWallet($company);

        CompanyAddonSubscription::create([
            'company_id' => $company->id,
            'module_key' => 'test_module',
            'interval' => 'monthly',
            'amount_cents' => 500,
            'currency' => $wallet->currency,
            'activated_at' => now(),
        ]);

        $addon = CompanyAddonSubscription::where('company_id', $company->id)->first();
        $this->assertEquals('GBP', $addon->currency);
    }

    // ── 7: Overview returns correct currency ────────────

    public function test_overview_returns_market_currency(): void
    {
        $company = $this->createCompanyInMarket('GB', 'Overview Co');

        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($company);

        $this->assertEquals('GBP', $overview['currency']);
    }

    // ── 8: Overview read returns correct currency from market ───

    public function test_overview_returns_market_currency_when_no_wallet(): void
    {
        $company = $this->createCompanyInMarket('GB', 'NoWallet Co');

        $overview = \App\Core\Billing\ReadModels\CompanyBillingReadService::overview($company);

        $this->assertEquals('GBP', $overview['currency']);
    }

    // ── 9: Credit note uses wallet currency ───────────────

    public function test_credit_note_uses_wallet_currency(): void
    {
        $company = $this->createCompanyInMarket('GB', 'CreditNote Co');

        $cn = \App\Core\Billing\CreditNoteIssuer::createDraft(
            $company,
            1000,
            'Test credit',
        );

        $this->assertEquals('GBP', $cn->currency);
    }
}
