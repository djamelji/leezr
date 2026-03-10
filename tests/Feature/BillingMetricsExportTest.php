<?php

namespace Tests\Feature;

use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-311: Prometheus metrics export tests.
 */
class BillingMetricsExportTest extends TestCase
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
    }

    public function test_metrics_requires_valid_token(): void
    {
        config(['billing.metrics.token' => 'secret-token']);

        $response = $this->getJson('/api/platform/billing/metrics-export');

        $response->assertStatus(403);
    }

    public function test_metrics_returns_prometheus_format(): void
    {
        config(['billing.metrics.token' => 'secret-token']);

        $response = $this->getJson('/api/platform/billing/metrics-export', [
            'Authorization' => 'Bearer secret-token',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertStringContainsString('billing_subscriptions_total', $response->getContent());
        $this->assertStringContainsString('billing_invoices_total', $response->getContent());
    }

    public function test_metrics_counts_match_database(): void
    {
        config(['billing.metrics.token' => 'secret-token']);

        $company = Company::create([
            'name' => 'Metrics Co',
            'slug' => 'metrics-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'market_key' => 'FR',
            'jobdomain_key' => 'logistique',
        ]);

        Subscription::create([
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

        Invoice::create([
            'company_id' => $company->id,
            'status' => 'open',
            'subtotal' => 2900,
            'amount' => 2900,
            'amount_due' => 2900,
            'currency' => 'EUR',
        ]);

        $response = $this->getJson('/api/platform/billing/metrics-export', [
            'Authorization' => 'Bearer secret-token',
        ]);

        $content = $response->getContent();

        $this->assertStringContainsString('billing_subscriptions_total{status="active"} 1', $content);
        $this->assertStringContainsString('billing_invoices_total{status="open"} 1', $content);
    }
}
