<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCoupon;
use App\Core\Billing\BillingMetricsCalculationService;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerService;
use App\Core\Billing\Payment;
use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-841 (Leezr 4.2 M4) — one recurring-revenue definition for every Platform screen, ledger-based invoiced /
 * collected / outstanding kept apart. Expected amounts are computed BY HAND in each test (catalogue : starter 0,
 * pro 29 €/290 €, business 79 €/790 €), never by calling the code under test.
 */
class Adr841FinancialIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        MarketRegistry::sync();
        PlanRegistry::sync();
        PlatformPermissionCatalog::sync();
        Carbon::setTestNow(Carbon::parse('2026-03-16 10:00:00'));
        Cache::flush();

        // A monthly pro active · B yearly business active · C trial · D past_due pro · E cancelled · F no subscription
        // + add-on · G pro + add-on + 20 % coupon · H free plan
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $k) {
            $this->c[$k] = Company::create(['name' => "Fictive $k", 'slug' => 'fictive-'.strtolower($k), 'plan_key' => 'pro', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        }
        $this->sub('A', 'pro', 'monthly', 'active', 2900);
        $this->sub('B', 'business', 'yearly', 'active', 79000);
        $this->sub('C', 'pro', 'monthly', 'trialing', 2900, ['trial_ends_at' => now()->addDays(10)]);
        $this->sub('D', 'pro', 'monthly', 'past_due', 2900);
        $this->sub('E', 'pro', 'monthly', 'cancelled', 2900);
        $this->addon('F', 1900);
        $coupon = BillingCoupon::create(['code' => 'M4-20', 'name' => 'M4 20 %', 'type' => 'percentage', 'value' => 2000, 'is_active' => true]);
        $this->sub('G', 'pro', 'monthly', 'active', 2900, ['coupon_id' => $coupon->id, 'coupon_months_remaining' => null]);
        $this->addon('G', 1900);
        $this->addon('C', 1900); // add-on during a trial : not recurring revenue yet
        $this->sub('H', 'starter', 'monthly', 'active', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sub(string $k, string $plan, string $interval, string $status, int $price, array $extra = []): Subscription
    {
        // created then put in its state directly (fixture : the state machine guards real transitions)
        $sub = Subscription::create(array_merge([
            'company_id' => $this->c[$k]->id, 'plan_key' => $plan, 'interval' => $interval, 'status' => 'active',
            'plan_price_cents' => $price, 'plan_price_source' => 'catalog', 'is_current' => 1,
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => $interval === 'yearly' ? now()->startOfMonth()->addYear() : now()->startOfMonth()->addMonth(),
        ], $extra));
        \Illuminate\Support\Facades\DB::table('subscriptions')->where('id', $sub->id)->update(['status' => $status]);

        return $sub->fresh();
    }

    private function addon(string $k, int $cents): void
    {
        CompanyAddonSubscription::create(['company_id' => $this->c[$k]->id, 'module_key' => 'workforce_leave', 'interval' => 'monthly', 'amount_cents' => $cents, 'currency' => 'EUR', 'activated_at' => now()->subMonth()]);
    }

    private function operator(array $permissions): PlatformUser
    {
        $user = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'M4', 'email' => Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'r_'.Str::random(6), 'name' => 'Role '.Str::random(4)]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user->roles()->attach($role);

        return $user;
    }

    // Expected by hand :
    //   A 2900 · B 79000/12 = 6583.33 → 6583 · D 2900 (past due, still contracted) · G (2900 + 1900) × 0.8 = 3840 · H 0
    //   excluded : C (trial, its add-on too), E (cancelled), F (no subscription, its add-on too)
    private const MRR = 2900 + 6583 + 2900 + 3840; // 16223

    public function test_1_to_3_mrr_definition_monthly_yearly_trial_coupon_addons(): void
    {
        $p = app(BillingMetricsCalculationService::class)->recurringPortfolio();
        $this->assertSame(self::MRR, $p['mrr']);
        $this->assertSame(self::MRR * 12, $p['arr'], 'ARR = 12 × MRR (add-ons no longer only in the ARR)');
        $this->assertSame(1900, $p['mrr_addons'], 'only the add-on of a billed subscription');
        $this->assertSame(960, $p['mrr_discounts']);
        $this->assertSame(2900, $p['past_due_mrr']);
        $this->assertSame(1, $p['trialing_subscriptions']);
        $this->assertSame([2900, 6583, 2900, 3840, 0], array_map(fn ($k) => $p['companies'][$this->c[$k]->id]['monthly'], ['A', 'B', 'D', 'G', 'H']));
        foreach (['C', 'E', 'F'] as $k) {
            $this->assertArrayNotHasKey($this->c[$k]->id, $p['companies'], "$k is not recurring revenue");
        }
    }

    public function test_every_platform_screen_shows_the_same_mrr(): void
    {
        $op = $this->operator(['view_billing', 'view_companies', 'view_dashboard']);
        $this->actingAs($op, 'platform');

        $this->assertSame(self::MRR, $this->getJson('/api/platform/billing/metrics')->assertOk()->json('mrr'));
        $this->assertSame(self::MRR * 12, $this->getJson('/api/platform/billing/metrics')->json('arr'));
        $this->assertSame(self::MRR, $this->getJson('/api/platform/billing/commercial-series?period=month')->assertOk()->json('kpis.mrr'));
        $this->assertSame(self::MRR, PlatformBillingWidgetsReadService::kpisDataset('global', null, now()->startOfMonth(), now())['mrr']);

        $list = $this->getJson('/api/platform/companies?per_page=50')->assertOk();
        $this->assertEqualsWithDelta(self::MRR / 100, $list->json('stats.total_mrr'), 0.001, 'companies list total = the same MRR (was : public price of every active company)');
        $rows = collect($list->json('data'))->keyBy('id');
        $this->assertEqualsWithDelta(38.40, $rows[$this->c['G']->id]['mrr'], 0.001);
        $this->assertEquals(0, $rows[$this->c['C']->id]['mrr'], 'a trial shows no recurring amount');
        $this->assertSame('trialing', $rows[$this->c['C']->id]['current_sub_status']);
        $this->assertEquals(0, $rows[$this->c['F']->id]['mrr'], 'no subscription : no MRR even with an add-on');
    }

    public function test_6_scheduled_change_is_not_counted_and_7_immediate_change_is_counted_once(): void
    {
        $op = $this->operator(['view_companies', 'manage_companies', 'view_billing']);
        $svc = app(BillingMetricsCalculationService::class);

        // 6 — downgrade business yearly → pro : end of period (policy) → MRR unchanged until executed
        $intent = $this->actingAs($op, 'platform')->putJson("/api/platform/companies/{$this->c['B']->id}/plan", ['plan_key' => 'pro'])->assertOk()->json('intent');
        $this->assertNotSame('executed', $intent['status']);
        $this->assertSame(6583, $svc->recurringPortfolio()['companies'][$this->c['B']->id]['monthly']);

        // 7 — upgrade pro → business monthly : immediate → counted ONCE at the new contracted price (79 €)
        $intent = $this->putJson("/api/platform/companies/{$this->c['A']->id}/plan", ['plan_key' => 'business'])->assertOk()->json('intent');
        $this->assertSame('executed', $intent['status']);
        $this->assertSame(1, Subscription::where('company_id', $this->c['A']->id)->where('is_current', 1)->count(), 'no double subscription');
        $p = $svc->recurringPortfolio();
        $this->assertSame(7900, $p['companies'][$this->c['A']->id]['monthly']);
        $this->assertSame(self::MRR - 2900 + 7900, $p['mrr'], 'the one-off proration never enters the MRR');
    }

    public function test_4_5_8_9_invoiced_collected_outstanding_refunded_come_from_the_ledger(): void
    {
        $company = $this->c['A'];
        $paid = Invoice::create(['company_id' => $company->id, 'number' => 'M4-0001', 'amount' => 12000, 'subtotal' => 10000, 'tax_amount' => 2000, 'tax_rate_bps' => 2000,
            'amount_due' => 12000, 'currency' => 'EUR', 'status' => 'paid', 'issued_at' => now(), 'paid_at' => now()]);
        LedgerService::recordInvoiceIssued($paid);
        $payment = Payment::create(['invoice_id' => $paid->id, 'company_id' => $company->id, 'amount' => 12000, 'currency' => 'EUR', 'status' => 'succeeded', 'provider' => 'internal']);
        LedgerService::recordPaymentReceived($payment);
        $unpaid = Invoice::create(['company_id' => $company->id, 'number' => 'M4-0002', 'amount' => 6000, 'subtotal' => 5000, 'tax_amount' => 1000, 'tax_rate_bps' => 2000,
            'amount_due' => 6000, 'currency' => 'EUR', 'status' => 'open', 'issued_at' => now()]);
        LedgerService::recordInvoiceIssued($unpaid);
        $refund = CreditNote::create(['number' => 'AV-M4-1', 'company_id' => $company->id, 'invoice_id' => $paid->id, 'amount' => 3000, 'currency' => 'EUR', 'reason' => 'M4', 'status' => 'issued', 'issued_at' => now()]);
        LedgerService::recordRefundIssued($refund);

        $k = PlatformBillingWidgetsReadService::kpisDataset('company', $company->id, now()->startOfMonth(), now()->endOfMonth());
        $this->assertEquals(15000, $k['revenue'], 'invoiced HT = 100 + 50 €, paid or not');
        $this->assertEquals(3000, $k['refunds']);
        $this->assertEquals(6000, $k['outstanding'], 'still due TTC = the unpaid invoice only');
        $this->assertSame(2900, $k['mrr'], 'MRR is independent of invoices, payments and refunds');

        // 8 — cancellation : out of the MRR
        \Illuminate\Support\Facades\DB::table('subscriptions')->where('company_id', $company->id)->update(['status' => 'cancelled']);
        $this->assertArrayNotHasKey($company->id, app(BillingMetricsCalculationService::class)->recurringPortfolio()['companies']);
    }

    public function test_10_financial_figures_follow_platform_rbac_in_the_api(): void
    {
        $reader = $this->operator(['view_companies']);
        $list = $this->actingAs($reader, 'platform')->getJson('/api/platform/companies')->assertOk();
        $this->assertNull($list->json('stats.total_mrr'), 'no financial total without view_billing');
        $this->assertNull($list->json('data.0.mrr'));
        $this->getJson('/api/platform/billing/metrics')->assertForbidden();
        $this->getJson('/api/platform/billing/commercial-series')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $finance = $this->operator(['view_billing']);
        $this->actingAs($finance, 'platform')->getJson('/api/platform/billing/metrics')->assertOk();
        $this->getJson('/api/platform/companies')->assertForbidden(); // finance alone does not list companies
    }

    public function test_company_routes_never_expose_another_company_finance(): void
    {
        $owner = User::factory()->create();
        $this->c['A']->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $r = $this->actingAs($owner)->withHeaders(['X-Company-Id' => $this->c['B']->id])->getJson('/api/billing/overview');
        $this->assertContains($r->status(), [403, 404]);
        $this->assertStringNotContainsString('79000', (string) $r->getContent());
    }
}
