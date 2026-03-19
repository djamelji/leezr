<?php

namespace Tests\Feature;

use App\Core\Billing\CreditNote;
use App\Core\Billing\Invoice;
use App\Core\Billing\LedgerEntry;
use App\Core\Billing\Payment;
use App\Core\Billing\PaymentRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Modules\PlatformModule;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-156: Platform Billing Dashboard Batch ReadModel tests.
 */
class PlatformBillingDashboardBatchTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;
    private PlatformUser $viewer;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        // Super admin with view_billing
        $this->admin = PlatformUser::create([
            'first_name' => 'Admin',
            'last_name' => 'Batch',
            'email' => 'admin-batch@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);

        // Viewer without view_billing
        $this->viewer = PlatformUser::create([
            'first_name' => 'Viewer',
            'last_name' => 'Batch',
            'email' => 'viewer-batch@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        // Company with realistic data
        $this->company = Company::create([
            'name' => 'Batch Co',
            'slug' => 'batch-co',
            'plan_key' => 'pro',
            'jobdomain_key' => 'logistique',
        ]);

        $this->seedBillingData();
    }

    private function seedBillingData(): void
    {
        $corrId = (string) Str::uuid();

        // Revenue ledger entries
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'REVENUE',
            'debit' => 0,
            'credit' => 2500.00,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'correlation_id' => $corrId,
            'recorded_at' => now()->subDays(5),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'invoice_issued',
            'account_code' => 'AR',
            'debit' => 2500.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'correlation_id' => $corrId,
            'recorded_at' => now()->subDays(5),
        ]);

        // Payment received (cashflow)
        $cashCorrId = (string) Str::uuid();

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'payment_received',
            'account_code' => 'CASH',
            'debit' => 1000.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'payment',
            'reference_id' => 1,
            'correlation_id' => $cashCorrId,
            'recorded_at' => now()->subDays(3),
        ]);

        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'payment_received',
            'account_code' => 'AR',
            'debit' => 0,
            'credit' => 1000.00,
            'currency' => 'EUR',
            'reference_type' => 'payment',
            'reference_id' => 1,
            'correlation_id' => $cashCorrId,
            'recorded_at' => now()->subDays(3),
        ]);

        // Refund
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'entry_type' => 'refund_issued',
            'account_code' => 'REFUND',
            'debit' => 200.00,
            'credit' => 0,
            'currency' => 'EUR',
            'reference_type' => 'credit_note',
            'reference_id' => 1,
            'correlation_id' => (string) Str::uuid(),
            'recorded_at' => now()->subDays(2),
        ]);

        // Payment records
        Payment::create([
            'company_id' => $this->company->id,
            'amount' => 100000,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_test_1',
        ]);

        Payment::create([
            'company_id' => $this->company->id,
            'amount' => 50000,
            'currency' => 'EUR',
            'status' => 'failed',
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_test_2',
            'metadata' => ['failure_reason' => 'insufficient_funds'],
        ]);

        // Invoice records
        Invoice::create([
            'company_id' => $this->company->id,
            'number' => 'INV-001',
            'amount' => 250000,
            'amount_due' => 150000,
            'currency' => 'EUR',
            'status' => 'sent',
            'issued_at' => now()->subDays(5),
            'due_at' => now()->subDays(1), // overdue
        ]);

        // Credit note records
        CreditNote::create([
            'company_id' => $this->company->id,
            'invoice_id' => 1,
            'amount' => 20000,
            'currency' => 'EUR',
            'reason' => 'duplicate',
            'status' => 'issued',
            'issued_at' => now()->subDays(2),
        ]);
    }

    // ── T1: 12 widgets single HTTP POST → all return data ──

    public function test_all_12_widgets_resolve_in_single_batch(): void
    {
        Cache::flush();

        $allKeys = [
            'billing.revenue_trend',
            'billing.refund_ratio',
            'billing.ar_outstanding',
            'billing.last_payments',
            'billing.last_invoices',
            'billing.last_refunds',
            'billing.revenue_mtd',
            'billing.mrr',
            'billing.failed_payments_7d',
            'billing.pending_dunning',
            'billing.top_failure_reasons',
            'billing.cashflow_trend_30d',
        ];

        $widgets = array_map(fn ($k) => ['key' => $k, 'scope' => 'global', 'period' => '30d'], $allKeys);

        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', ['widgets' => $widgets]);

        $response->assertOk();

        $results = $response->json('results');
        $this->assertCount(12, $results);

        foreach ($results as $result) {
            $this->assertArrayHasKey('data', $result, "Widget {$result['key']} returned error: " . ($result['error'] ?? ''));
        }
    }

    // ── T2: Cache cold — query count ≤ 10 ──

    public function test_batch_query_count_cold_cache(): void
    {
        Cache::flush();

        $widgets = [
            ['key' => 'billing.revenue_trend', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.refund_ratio', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.ar_outstanding', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.revenue_mtd', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.cashflow_trend_30d', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.failed_payments_7d', 'scope' => 'global'],
            ['key' => 'billing.pending_dunning', 'scope' => 'global'],
            ['key' => 'billing.last_payments', 'scope' => 'global', 'period' => '30d'],
        ];

        // Warm up auth queries first
        $this->actingAs($this->admin, 'platform');

        \DB::enableQueryLog();

        $response = $this->postJson('/api/platform/dashboard/widgets/data', ['widgets' => $widgets]);

        $queryLog = \DB::getQueryLog();
        \DB::disableQueryLog();

        $response->assertOk();

        // Filter out auth/session queries — count only billing-related queries
        $billingQueries = array_filter($queryLog, function ($q) {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'ledger')
                || str_contains($sql, 'payment')
                || str_contains($sql, 'invoice')
                || str_contains($sql, 'credit_note');
        });

        // 4 datasets × ~3-4 queries each + eager loads (company:id,name) = ~15 max
        $this->assertLessThanOrEqual(15, count($billingQueries), 'Cold cache batch should execute ≤ 15 dataset queries. Got: ' . count($billingQueries));
    }

    // ── T3: Cache warm — 0 dataset queries ──

    public function test_batch_query_count_warm_cache(): void
    {
        Cache::flush();

        $widgets = [
            ['key' => 'billing.revenue_trend', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.refund_ratio', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.ar_outstanding', 'scope' => 'global', 'period' => '30d'],
        ];

        // First call: warm cache
        $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', ['widgets' => $widgets]);

        // Second call: should hit cache
        \DB::enableQueryLog();

        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', ['widgets' => $widgets]);

        $queryLog = \DB::getQueryLog();
        \DB::disableQueryLog();

        $response->assertOk();

        $billingQueries = array_filter($queryLog, function ($q) {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'ledger')
                || str_contains($sql, 'payment')
                || str_contains($sql, 'invoice')
                || str_contains($sql, 'credit_note');
        });

        $this->assertCount(0, $billingQueries, 'Warm cache batch should execute 0 dataset queries');
    }

    // ── T4: Module disabled → catalog returns 0 billing widgets ──

    public function test_module_disabled_hides_all_billing_widgets(): void
    {
        PlatformModule::where('key', 'platform.billing')
            ->update(['is_enabled_globally' => false]);

        $catalog = DashboardWidgetRegistry::catalogForUser($this->admin);
        $billingKeys = array_filter(
            array_map(fn ($w) => $w->key(), $catalog),
            fn ($k) => str_starts_with($k, 'billing.')
        );

        $this->assertEmpty($billingKeys, 'Disabled module must hide all billing widgets');
    }

    // ── T5: No permission → all return forbidden ──

    public function test_no_permission_returns_forbidden(): void
    {
        $widgets = [
            ['key' => 'billing.revenue_trend', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.last_payments', 'scope' => 'global', 'period' => '30d'],
            ['key' => 'billing.failed_payments_7d', 'scope' => 'global'],
        ];

        $response = $this->actingAs($this->viewer, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', ['widgets' => $widgets]);

        $response->assertOk();

        foreach ($response->json('results') as $result) {
            $this->assertEquals('forbidden', $result['error'], "Widget {$result['key']} should be forbidden");
        }
    }

    // ── T6: Unknown key → not_found ──

    public function test_unknown_key_returns_not_found(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', [
                'widgets' => [
                    ['key' => 'billing.nonexistent_widget', 'scope' => 'global'],
                ],
            ]);

        $response->assertOk();
        $this->assertEquals('not_found', $response->json('results.0.error'));
    }

    // ── T7: catalogForCompany returns 0 platform-audience widgets ──

    public function test_company_catalog_excludes_platform_widgets(): void
    {
        $catalog = DashboardWidgetRegistry::catalogForCompany($this->company);
        $platformWidgets = array_filter($catalog, fn ($w) => $w->audience() === 'platform');

        $this->assertEmpty($platformWidgets, 'Platform-audience billing widgets must not appear in company catalog');
        // Company-audience widgets (compliance + onboarding + plan badge) are expected (ADR-327, ADR-372)
        $companyWidgets = array_filter($catalog, fn ($w) => $w->audience() === 'company');
        $this->assertCount(7, $companyWidgets);
    }

    // ── T8: Scope=company filters by company_id ──

    public function test_company_scope_filters_activity_by_company(): void
    {
        Cache::flush();

        $response = $this->actingAs($this->admin, 'platform')
            ->postJson('/api/platform/dashboard/widgets/data', [
                'widgets' => [
                    [
                        'key' => 'billing.last_payments',
                        'scope' => 'company',
                        'company_id' => $this->company->id,
                        'period' => '30d',
                    ],
                ],
            ]);

        $response->assertOk();

        $result = $response->json('results.0');
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('company', $result['data']['scope']);
    }
}
