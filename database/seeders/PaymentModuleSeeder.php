<?php

namespace Database\Seeders;

use App\Core\Billing\CompanyPaymentCustomer;
use App\Core\Billing\CompanyPaymentProfile;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds payment modules, default payment rules, and dev Stripe test data.
 * Idempotent: uses updateOrCreate.
 */
class PaymentModuleSeeder extends Seeder
{
    public function run(): void
    {
        // Internal payment module (always installed + active)
        PlatformPaymentModule::updateOrCreate(
            ['provider_key' => 'internal'],
            [
                'name' => 'Internal (Manual)',
                'description' => 'Built-in manual payment processing — no external provider required.',
                'is_installed' => true,
                'is_active' => true,
                'health_status' => 'healthy',
                'sort_order' => 0,
            ],
        );

        // Default rule: manual method via internal provider (disabled — Stripe preferred)
        PlatformPaymentMethodRule::updateOrCreate(
            [
                'method_key' => 'manual',
                'provider_key' => 'internal',
                'market_key' => null,
                'plan_key' => null,
                'interval' => null,
            ],
            [
                'priority' => 0,
                'is_active' => false,
            ],
        );

        // Stripe payment module (test mode for dev)
        if (app()->environment('local', 'testing')) {
            // ADR-301: Stripe test credentials hardcoded for dev/test idempotency
            $stripeModule = PlatformPaymentModule::updateOrCreate(
                ['provider_key' => 'stripe'],
                [
                    'name' => 'Stripe',
                    'description' => 'Stripe payment processing — cards, SEPA, Apple Pay.',
                    'is_installed' => true,
                    'is_active' => true,
                    'credentials' => [
                        'mode' => 'test',
                        'test_publishable_key' => env('STRIPE_TEST_PUBLISHABLE_KEY', 'pk_test_51T7WVLDI550p59YnWzGYzASGOQvruzkxPZtfxcR7GQ4vUWFndjE8MJyOra9AcFZsaOtorPg3HPaZ9IYLjmOqq2j100fNenvCy5'),
                        'test_secret_key' => env('STRIPE_TEST_SECRET_KEY', 'sk_test_51T7WVLDI550p59Yntb7AnyRUcPgC10oJFlbDJcMoWBtlbktV7R18OTXtfKlOsepsrjsyyK6N5Jso640MP9WN2S8b00l9tihF6X'),
                        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', 'whsec_y5DCO0mD6OzBbSAcUeky3eGfwHKTlsfc'),
                    ],
                    'health_status' => 'healthy',
                    'sort_order' => 1,
                ],
            );

            // Default rule: card via Stripe
            PlatformPaymentMethodRule::updateOrCreate(
                [
                    'method_key' => 'card',
                    'provider_key' => 'stripe',
                    'market_key' => null,
                    'plan_key' => null,
                    'interval' => null,
                ],
                [
                    'priority' => 10,
                    'is_active' => true,
                ],
            );

            // Default rule: SEPA Direct Debit via Stripe
            PlatformPaymentMethodRule::updateOrCreate(
                [
                    'method_key' => 'sepa_debit',
                    'provider_key' => 'stripe',
                    'market_key' => null,
                    'plan_key' => null,
                    'interval' => null,
                ],
                [
                    'priority' => 5,
                    'is_active' => true,
                ],
            );

            // Seed a test Visa card for Leezr Logistics
            $leezr = \App\Core\Models\Company::where('slug', 'leezr-logistics')->first();
            if ($leezr) {
                $this->seedTestCard($stripeModule, $leezr->id);
            }
        }
    }

    /**
     * Create a real Stripe test customer + attach pm_card_visa for a company.
     */
    private function seedTestCard(PlatformPaymentModule $module, int $companyId): void
    {
        // Skip if already has a payment profile
        if (CompanyPaymentProfile::where('company_id', $companyId)->exists()) {
            return;
        }

        $secretKey = $module->getStripeSecretKey();
        if (! $secretKey) {
            Log::warning('[seeder] Stripe secret key not available — skipping test card seed.');

            return;
        }

        try {
            \Stripe\Stripe::setApiKey($secretKey);

            $company = \App\Core\Models\Company::find($companyId);
            if (! $company) {
                return;
            }

            // Ensure Stripe customer
            $customerRecord = CompanyPaymentCustomer::where('company_id', $companyId)
                ->where('provider_key', 'stripe')
                ->first();

            if (! $customerRecord) {
                $stripeCustomer = \Stripe\Customer::create([
                    'name' => $company->name,
                    'metadata' => ['company_id' => (string) $companyId],
                ]);

                $customerRecord = CompanyPaymentCustomer::create([
                    'company_id' => $companyId,
                    'provider_key' => 'stripe',
                    'provider_customer_id' => $stripeCustomer->id,
                ]);
            }

            // Create test PaymentMethod (4242 4242 4242 4242)
            $pm = \Stripe\PaymentMethod::create([
                'type' => 'card',
                'card' => ['token' => 'tok_visa'],
            ]);

            // Attach to customer
            $pm->attach(['customer' => $customerRecord->provider_customer_id]);

            // Set as default
            \Stripe\Customer::update($customerRecord->provider_customer_id, [
                'invoice_settings' => ['default_payment_method' => $pm->id],
            ]);

            // Save locally
            CompanyPaymentProfile::create([
                'company_id' => $companyId,
                'provider_key' => 'stripe',
                'method_key' => 'card',
                'provider_payment_method_id' => $pm->id,
                'label' => 'Visa •••• 4242',
                'is_default' => true,
                'metadata' => [
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => $pm->card->exp_month,
                    'exp_year' => $pm->card->exp_year,
                ],
            ]);

            Log::info("[seeder] Test Visa card seeded for company {$companyId} (pm: {$pm->id})");
        } catch (\Throwable $e) {
            Log::warning("[seeder] Failed to seed test card for company {$companyId}: {$e->getMessage()}");
        }
    }
}
