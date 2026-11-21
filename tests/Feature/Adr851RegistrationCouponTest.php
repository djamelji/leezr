<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\Subscription;
use App\Core\Documents\DocumentTypeCatalog;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-851 — the promo code typed at registration reaches the server, is validated by the SAME engine as the public
 * preview and the company side BEFORE anything is created, is refused explicitly with its reason, is attached to the
 * new subscription (re-checked under lock), and never touches existing subscriptions.
 */
class Adr851RegistrationCouponTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        PlanRegistry::sync();
        FieldDefinitionCatalog::sync();
        DocumentTypeCatalog::sync();
    }

    private function coupon(array $attrs = []): BillingCoupon
    {
        return BillingCoupon::create($attrs + ['code' => 'BIENVENUE20', 'name' => 'Bienvenue', 'type' => 'percentage', 'value' => 2000, 'is_active' => true, 'duration_months' => 3]);
    }

    private function register(string $email, ?string $code, string $plan = 'pro', string $interval = 'monthly')
    {
        return $this->postJson('/api/register', array_filter(['first_name' => 'R', 'last_name' => 'U', 'email' => $email, 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'Promo '.$email, 'jobdomain_key' => 'logistique', 'plan_key' => $plan,
            'billing_interval' => $interval, 'dynamic_fields' => ['siret' => '12345678901234'], 'coupon_code' => $code]));
    }

    public function test_a_valid_code_is_attached_to_the_new_subscription_case_insensitively(): void
    {
        $coupon = $this->coupon();
        $companyId = $this->register('valid@example.test', 'bienvenue20')->assertCreated()->json('company.id');
        $sub = Subscription::where('company_id', $companyId)->where('is_current', 1)->firstOrFail();
        $this->assertSame($coupon->id, $sub->coupon_id);
        $this->assertSame(3, $sub->coupon_months_remaining);
    }

    public function test_first_purchase_codes_are_accepted_at_registration(): void
    {
        // was always rejected : re-validated after the subscription had just been created
        $coupon = $this->coupon(['code' => 'PREMIER', 'first_purchase_only' => true]);
        $companyId = $this->register('first@example.test', 'PREMIER')->assertCreated()->json('company.id');
        $this->assertSame($coupon->id, Subscription::where('company_id', $companyId)->where('is_current', 1)->value('coupon_id'));
    }

    public function test_invalid_expired_exhausted_or_ineligible_codes_are_refused_and_nothing_is_created(): void
    {
        $this->coupon(['code' => 'EXPIRE', 'expires_at' => now()->subDay()]);
        $this->coupon(['code' => 'EPUISE', 'max_uses' => 1, 'used_count' => 1]);
        $this->coupon(['code' => 'ANNUEL', 'applicable_billing_cycles' => ['yearly']]);
        $this->coupon(['code' => 'BUSINESS', 'applicable_plan_keys' => ['business']]);
        $this->coupon(['code' => 'PLUSTARD', 'starts_at' => now()->addWeek()]);

        $cases = ['INCONNU' => "Ce code promo n'existe pas.", 'EXPIRE' => 'Ce code promo a expiré.', 'EPUISE' => "Ce code promo a atteint sa limite d'utilisation.",
            'ANNUEL' => "Ce code promo ne s'applique pas à cette périodicité de facturation.", 'BUSINESS' => "Ce code promo ne s'applique pas à cette offre.",
            'PLUSTARD' => "Ce code promo n'est plus actif."];
        foreach ($cases as $code => $reason) {
            $this->withHeaders(['X-Locale' => 'fr'])->register(strtolower($code).'@example.test', $code)
                ->assertUnprocessable()->assertJsonPath('errors.coupon_code.0', $reason);
            $this->assertFalse(User::where('email', strtolower($code).'@example.test')->exists(), "$code : no account created");
        }
        $this->assertSame(0, Company::count(), 'nothing created');

        // the same yearly-only code IS valid for a yearly subscription
        $this->register('yearly@example.test', 'ANNUEL', 'pro', 'yearly')->assertCreated();
    }

    public function test_public_preview_and_price_shown_use_the_same_rule(): void
    {
        $this->coupon(['code' => 'ANNUEL', 'applicable_billing_cycles' => ['yearly']]);
        $this->postJson('/api/public/validate-coupon', ['code' => 'annuel', 'plan_key' => 'pro', 'billing_interval' => 'yearly', 'subtotal_cents' => 29000])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('discount_preview', 5800);
        $this->postJson('/api/public/validate-coupon', ['code' => 'ANNUEL', 'plan_key' => 'pro', 'billing_interval' => 'monthly'])
            ->assertOk()->assertJsonPath('valid', false)->assertJsonPath('error', 'coupon_billing_cycle_mismatch');

        $yearly = $this->postJson('/api/public/plans/estimate-registration', ['plan_key' => 'pro', 'interval' => 'yearly', 'market_key' => 'FR', 'coupon_code' => 'annuel'])->assertOk()->json();
        $monthly = $this->postJson('/api/public/plans/estimate-registration', ['plan_key' => 'pro', 'interval' => 'monthly', 'market_key' => 'FR', 'coupon_code' => 'annuel'])->assertOk()->json();
        $this->assertGreaterThan(0, (int) data_get($yearly, 'coupon.discount'), 'final price shows the discount (case-insensitive)');
        $this->assertSame(0, (int) data_get($monthly, 'coupon.discount', 0), 'an ineligible code is not shown as a discount');
    }

    public function test_existing_subscriptions_are_never_touched(): void
    {
        $other = Company::create(['name' => 'Historique', 'slug' => 'historique-851', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        DB::table('subscriptions')->insert(['company_id' => $other->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active', 'is_current' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $before = json_encode(DB::table('subscriptions')->where('company_id', $other->id)->get());
        $this->coupon();
        $this->register('new@example.test', 'BIENVENUE20')->assertCreated();
        $this->assertSame($before, json_encode(DB::table('subscriptions')->where('company_id', $other->id)->get()));
    }
}
