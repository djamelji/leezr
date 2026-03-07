<?php

namespace Tests\Feature;

use App\Core\Billing\BillingCheckoutSession;
use App\Core\Billing\BillingJobHeartbeat;
use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Billing\PlatformPaymentModule;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-233: Billing Cron Resilience.
 *
 * Tests: heartbeat recording, ops-status anomaly detection.
 */
class BillingAdr233Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        PlatformPaymentModule::create([
            'provider_key' => 'stripe',
            'name' => 'Stripe',
            'is_installed' => true,
            'is_active' => true,
            'health_status' => 'healthy',
        ]);

        $owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'ADR233 Co',
            'slug' => 'adr233-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
    }

    // ── 1: Heartbeat start/finish ───────────────────────

    public function test_heartbeat_records_start_and_finish(): void
    {
        BillingJobHeartbeat::start('test:job');

        $this->assertDatabaseHas('billing_job_heartbeats', [
            'job_key' => 'test:job',
        ]);

        $hb = BillingJobHeartbeat::find('test:job');
        $this->assertNotNull($hb->last_started_at);
        $this->assertNull($hb->last_status);

        BillingJobHeartbeat::finish('test:job', 'ok', ['processed' => 5]);

        $hb->refresh();
        $this->assertNotNull($hb->last_finished_at);
        $this->assertEquals('ok', $hb->last_status);
        $this->assertEquals(['processed' => 5], $hb->last_run_stats);
    }

    // ── 2: Heartbeat idempotent ─────────────────────────

    public function test_heartbeat_is_idempotent(): void
    {
        BillingJobHeartbeat::start('test:idem');
        BillingJobHeartbeat::finish('test:idem', 'ok');
        BillingJobHeartbeat::start('test:idem');
        BillingJobHeartbeat::finish('test:idem', 'ok', ['run' => 2]);

        $this->assertDatabaseCount('billing_job_heartbeats', 1);

        $hb = BillingJobHeartbeat::find('test:idem');
        $this->assertEquals(['run' => 2], $hb->last_run_stats);
    }

    // ── 3: ops-status returns 0 when healthy ────────────

    public function test_ops_status_healthy(): void
    {
        $this->artisan('billing:ops-status')
            ->assertExitCode(0);
    }

    // ── 4: ops-status returns 1 with stale checkouts ────

    public function test_ops_status_detects_stale_checkouts(): void
    {
        $sub = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'pending_payment',
            'provider' => 'stripe',
        ]);

        $session = BillingCheckoutSession::create([
            'company_id' => $this->company->id,
            'subscription_id' => $sub->id,
            'provider_key' => 'stripe',
            'provider_session_id' => 'cs_stale_ops',
            'status' => 'created',
        ]);

        // Force old created_at
        BillingCheckoutSession::where('id', $session->id)
            ->update(['created_at' => now()->subHours(2)]);

        $this->artisan('billing:ops-status')
            ->assertExitCode(1);
    }

    // ── 5: Heartbeat recorded by billing:renew ──────────

    public function test_billing_renew_records_heartbeat(): void
    {
        $this->artisan('billing:renew')
            ->assertExitCode(0);

        $this->assertDatabaseHas('billing_job_heartbeats', [
            'job_key' => 'billing:renew',
            'last_status' => 'ok',
        ]);
    }

    // ── 6: ops-status returns 1 with dead letters pending ─

    public function test_ops_status_detects_dead_letters(): void
    {
        BillingWebhookDeadLetter::create([
            'provider_key' => 'stripe',
            'event_id' => 'evt_dead_001',
            'event_type' => 'payment_intent.succeeded',
            'payload' => '{}',
            'error_message' => 'Processing failed',
            'failed_at' => now(),
            'status' => 'pending',
        ]);

        $this->artisan('billing:ops-status')
            ->assertExitCode(1);
    }

    // ── 7: process-dunning records heartbeat ──────────────

    public function test_process_dunning_records_heartbeat(): void
    {
        $this->artisan('billing:process-dunning')
            ->assertExitCode(0);

        $this->assertDatabaseHas('billing_job_heartbeats', [
            'job_key' => 'billing:process-dunning',
            'last_status' => 'ok',
        ]);
    }

    // ── 8: ops-status returns 1 with failed heartbeat ─────

    public function test_ops_status_detects_failed_heartbeat(): void
    {
        BillingJobHeartbeat::start('billing:test-job');
        BillingJobHeartbeat::finish('billing:test-job', 'failed', [], 'Something broke');

        $this->artisan('billing:ops-status')
            ->assertExitCode(1);
    }
}
