<?php

namespace Tests\Feature;

use App\Core\Billing\LedgerEntry;
use App\Core\Billing\PaymentRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-147 D4e: Platform billing widgets endpoint tests.
 */
class PlatformBillingWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $platformAdmin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();

        // Platform admin with view_billing (super_admin)
        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'testadmin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);

        // Company with ledger entries
        $this->company = Company::create([
            'name' => 'Widget Co',
            'slug' => 'widget-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        // Seed ledger entries for revenue + refund + AR
        $corrInv = (string) \Illuminate\Support\Str::uuid();
        $corrPay = (string) \Illuminate\Support\Str::uuid();
        $corrRef = (string) \Illuminate\Support\Str::uuid();

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'REVENUE',
            'debit' => 0,
            'credit' => 500.00,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'correlation_id' => $corrInv,
            'recorded_at' => now()->subDays(5),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'AR',
            'debit' => 500.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'correlation_id' => $corrInv,
            'recorded_at' => now()->subDays(5),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'payment_received',
            'account_code' => 'AR',
            'debit' => 0,
            'credit' => 200.00,
            'currency' => 'EUR',
            'reference_type' => 'payment',
            'reference_id' => 1,
            'correlation_id' => $corrPay,
            'recorded_at' => now()->subDays(3),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'refund_issued',
            'account_code' => 'REFUND',
            'debit' => 50.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'credit_note',
            'reference_id' => 1,
            'correlation_id' => $corrRef,
            'recorded_at' => now()->subDays(2),
        ]);
    }

    // ── C1: index requires auth ──

    public function test_widgets_index_requires_auth(): void
    {
        $response = $this->getJson('/api/platform/billing/widgets?company_id=' . $this->company->id);

        $response->assertStatus(401);
    }

    // ── C2: index requires view_billing ──

    public function test_widgets_index_requires_view_billing(): void
    {
        $viewer = PlatformUser::create([
            'first_name' => 'Viewer',
            'last_name' => 'NoPerms',
            'email' => 'viewer@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $response = $this->actingAs($viewer, 'platform')
            ->getJson('/api/platform/billing/widgets?company_id=' . $this->company->id);

        $response->assertStatus(403);
    }

    // ── C3: index returns 3 widgets ──

    public function test_widgets_index_returns_three_widgets(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets?company_id=' . $this->company->id);

        $response->assertOk()
            ->assertJsonCount(3, 'widgets')
            ->assertJsonStructure([
                'widgets' => [
                    ['key', 'label_key', 'default_period'],
                ],
            ]);

        $keys = collect($response->json('widgets'))->pluck('key')->sort()->values()->all();
        $this->assertEquals(['ar_outstanding', 'refund_ratio', 'revenue_trend'], $keys);
    }

    // ── C4: show unknown widget returns 404 ──

    public function test_show_unknown_widget_returns_404(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets/unknown_widget?company_id=' . $this->company->id);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Widget not found.');
    }

    // ── C5: revenue_trend returns labels + series ──

    public function test_show_revenue_trend_returns_chart_data(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets/revenue_trend?company_id=' . $this->company->id . '&period=30d');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['key', 'currency', 'period', 'chart' => ['labels', 'series']],
            ]);

        $chart = $response->json('data.chart');
        $this->assertGreaterThan(0, count($chart['labels']));
        $this->assertCount(count($chart['labels']), $chart['series']);
    }

    // ── C6: refund_ratio returns revenue/refunds/ratio ──

    public function test_show_refund_ratio_returns_ratio(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets/refund_ratio?company_id=' . $this->company->id . '&period=30d');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['key', 'currency', 'period', 'revenue', 'refunds', 'ratio'],
            ]);

        $data = $response->json('data');
        $this->assertEquals(500.0, $data['revenue']);
        $this->assertEquals(50.0, $data['refunds']);
        $this->assertEquals(10.0, $data['ratio']);
    }

    // ── C7: ar_outstanding returns outstanding ──

    public function test_show_ar_outstanding_returns_balance(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets/ar_outstanding?company_id=' . $this->company->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['key', 'currency', 'outstanding'],
            ]);

        // AR: 500 debit - 200 credit = 300 outstanding
        $this->assertEquals(300.0, $response->json('data.outstanding'));
    }

    // ── C8: resolve validates company_id 422 ──

    public function test_resolve_validates_company_id(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets/revenue_trend');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['company_id']);
    }

    // ── C9: resolve validates period enum 422 ──

    public function test_resolve_validates_period_enum(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets/revenue_trend?company_id=' . $this->company->id . '&period=999d');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }

    // ── C10: mixed currencies returns 409 ──

    public function test_mixed_currencies_returns_409(): void
    {
        // Add a ledger entry with different currency
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'REVENUE',
            'debit' => 0,
            'credit' => 100.00,
            'currency' => 'USD',
            'reference_type' => 'invoice',
            'reference_id' => 99,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'recorded_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->getJson('/api/platform/billing/widgets/revenue_trend?company_id=' . $this->company->id . '&period=30d');

        $response->assertStatus(409);
        $this->assertStringContains('Mixed currencies', $response->json('message'));
    }

    /**
     * Custom assertion for string contains (compatible with PHPUnit 11).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'."
        );
    }
}
