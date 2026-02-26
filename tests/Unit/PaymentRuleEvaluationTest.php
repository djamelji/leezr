<?php

namespace Tests\Unit;

use App\Core\Billing\PaymentOrchestrator;
use App\Core\Billing\PlatformPaymentMethodRule;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for PaymentOrchestrator rule evaluation algorithm.
 *
 * Covers: matching, specificity scoring, priority tie-breaking,
 * deduplication per method_key, and provider active/installed filtering.
 */
class PaymentRuleEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        // Create two active+installed providers used across tests
        PlatformPaymentModule::create([
            'provider_key' => 'internal',
            'name' => 'Internal',
            'is_installed' => true,
            'is_active' => true,
        ]);

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
        ]);
    }

    // ─── 1. Empty rules ─────────────────────────────────────

    public function test_empty_rules_returns_empty(): void
    {
        $result = PaymentOrchestrator::resolveMethodsForContext('FR', 'starter', 'monthly');

        $this->assertEmpty($result);
    }

    // ─── 2. Exact match ─────────────────────────────────────

    public function test_exact_match_returns_method(): void
    {
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'stripe',
            'market_key' => 'FR',
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'priority' => 10,
            'is_active' => true,
        ]);

        $result = PaymentOrchestrator::resolveMethodsForContext('FR', 'starter', 'monthly');

        $this->assertCount(1, $result);
        $this->assertEquals('card', $result[0]['method_key']);
        $this->assertEquals('stripe', $result[0]['provider_key']);
        $this->assertEquals(10, $result[0]['priority']);
    }

    // ─── 3. Null dimensions match any context ───────────────

    public function test_null_dimensions_match_any_context(): void
    {
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'stripe',
            'market_key' => null,
            'plan_key' => null,
            'interval' => null,
            'priority' => 5,
            'is_active' => true,
        ]);

        // Should match any context since all dimensions are null (wildcard)
        $result = PaymentOrchestrator::resolveMethodsForContext('US', 'enterprise', 'yearly');

        $this->assertCount(1, $result);
        $this->assertEquals('card', $result[0]['method_key']);
        $this->assertEquals('stripe', $result[0]['provider_key']);
    }

    // ─── 4. Specificity scoring ─────────────────────────────

    public function test_specificity_scoring_correct(): void
    {
        // Generic rule (0 non-null dims → specificity 0) — internal provider
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'internal',
            'market_key' => null,
            'plan_key' => null,
            'interval' => null,
            'priority' => 100,
            'is_active' => true,
        ]);

        // Fully specific rule (3 non-null dims → specificity 3) — stripe provider
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'stripe',
            'market_key' => 'FR',
            'plan_key' => 'starter',
            'interval' => 'monthly',
            'priority' => 1,
            'is_active' => true,
        ]);

        $result = PaymentOrchestrator::resolveMethodsForContext('FR', 'starter', 'monthly');

        // Deduplication keeps only the highest-scoring rule per method_key
        // Specificity 3 (stripe) beats specificity 0 (internal), even though internal has higher priority
        $this->assertCount(1, $result);
        $this->assertEquals('stripe', $result[0]['provider_key']);

        // Verify via preview that specificity is correctly computed
        $preview = PaymentOrchestrator::previewMethodsForContext('FR', 'starter', 'monthly');
        $this->assertCount(1, $preview);
        $this->assertEquals(3, $preview[0]['specificity']);
    }

    // ─── 5. Priority breaks tie within same specificity ─────

    public function test_priority_breaks_tie_within_same_specificity(): void
    {
        // Both rules have same specificity (1 non-null dim: market_key)
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'internal',
            'market_key' => 'FR',
            'plan_key' => null,
            'interval' => null,
            'priority' => 5,
            'is_active' => true,
        ]);

        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'stripe',
            'market_key' => 'FR',
            'plan_key' => null,
            'interval' => null,
            'priority' => 20,
            'is_active' => true,
        ]);

        $result = PaymentOrchestrator::resolveMethodsForContext('FR', 'starter', 'monthly');

        // Same specificity (1) → higher priority wins → stripe (priority 20)
        $this->assertCount(1, $result);
        $this->assertEquals('stripe', $result[0]['provider_key']);
        $this->assertEquals(20, $result[0]['priority']);
    }

    // ─── 6. Deduplication per method_key ────────────────────

    public function test_deduplication_per_method_key(): void
    {
        // Two rules for same method_key 'card', different providers
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'stripe',
            'market_key' => 'FR',
            'plan_key' => null,
            'interval' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'internal',
            'market_key' => null,
            'plan_key' => null,
            'interval' => null,
            'priority' => 50,
            'is_active' => true,
        ]);

        $result = PaymentOrchestrator::resolveMethodsForContext('FR', 'starter', 'monthly');

        // Only one 'card' entry kept — stripe wins (specificity 1 > 0)
        $this->assertCount(1, $result);
        $this->assertEquals('stripe', $result[0]['provider_key']);
    }

    // ─── 7. Inactive rules excluded ─────────────────────────

    public function test_inactive_rules_excluded(): void
    {
        PlatformPaymentMethodRule::create([
            'method_key' => 'card',
            'provider_key' => 'stripe',
            'market_key' => null,
            'plan_key' => null,
            'interval' => null,
            'priority' => 10,
            'is_active' => false,
        ]);

        $result = PaymentOrchestrator::resolveMethodsForContext('FR', 'starter', 'monthly');

        $this->assertEmpty($result);
    }

    // ─── 8. Inactive provider filtered from resolve ─────────

    public function test_inactive_provider_filtered_from_resolve(): void
    {
        // Create a provider that is installed but NOT active
        PlatformPaymentModule::create([
            'provider_key' => 'paypal',
            'name' => 'PayPal',
            'is_installed' => true,
            'is_active' => false,
        ]);

        PlatformPaymentMethodRule::create([
            'method_key' => 'wallet',
            'provider_key' => 'paypal',
            'market_key' => null,
            'plan_key' => null,
            'interval' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        // resolveMethodsForContext filters out inactive providers
        $resolve = PaymentOrchestrator::resolveMethodsForContext('FR', 'starter', 'monthly');
        $this->assertEmpty($resolve);

        // previewMethodsForContext still returns it (no provider filtering)
        $preview = PaymentOrchestrator::previewMethodsForContext('FR', 'starter', 'monthly');
        $this->assertCount(1, $preview);
        $this->assertEquals('wallet', $preview[0]['method_key']);
        $this->assertEquals('paypal', $preview[0]['provider_key']);
    }

    // ─── 9. Uninstalled provider filtered from resolve ──────

    public function test_uninstalled_provider_filtered_from_resolve(): void
    {
        // Create a provider that is NOT installed
        PlatformPaymentModule::create([
            'provider_key' => 'mollie',
            'name' => 'Mollie',
            'is_installed' => false,
            'is_active' => false,
        ]);

        PlatformPaymentMethodRule::create([
            'method_key' => 'ideal',
            'provider_key' => 'mollie',
            'market_key' => 'NL',
            'plan_key' => null,
            'interval' => null,
            'priority' => 10,
            'is_active' => true,
        ]);

        // resolveMethodsForContext filters out uninstalled providers
        $resolve = PaymentOrchestrator::resolveMethodsForContext('NL', 'starter', 'monthly');
        $this->assertEmpty($resolve);
    }
}
