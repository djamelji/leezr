<?php

namespace Tests\Feature;

use App\Core\Billing\BillingWebhookDeadLetter;
use App\Core\Plans\PlanRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-266: DLQ auto-escalation command tests.
 */
class BillingCheckDlqCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        PlanRegistry::sync();
    }

    public function test_dlq_below_threshold_returns_success(): void
    {
        // Create 2 pending entries (below default threshold of 10)
        BillingWebhookDeadLetter::create([
            'provider_key' => 'stripe',
            'event_id' => 'evt_test_1',
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['test' => true],
            'error_message' => 'Processing failed',
            'failed_at' => now(),
            'status' => 'pending',
        ]);

        BillingWebhookDeadLetter::create([
            'provider_key' => 'stripe',
            'event_id' => 'evt_test_2',
            'event_type' => 'checkout.session.completed',
            'payload' => ['test' => true],
            'error_message' => 'Processing failed',
            'failed_at' => now(),
            'status' => 'pending',
        ]);

        $this->artisan('billing:check-dlq')
            ->assertExitCode(0);
    }

    public function test_dlq_at_threshold_returns_failure(): void
    {
        // Create entries at the threshold
        for ($i = 0; $i < 5; $i++) {
            BillingWebhookDeadLetter::create([
                'provider_key' => 'stripe',
                'event_id' => "evt_test_{$i}",
                'event_type' => 'payment_intent.succeeded',
                'payload' => ['test' => true],
                'error_message' => 'Processing failed',
                'failed_at' => now()->subHours($i),
                'status' => 'pending',
            ]);
        }

        $this->artisan('billing:check-dlq', ['--threshold' => 5])
            ->assertExitCode(1);
    }

    public function test_dlq_resolved_entries_not_counted(): void
    {
        // Create entries with mixed statuses
        BillingWebhookDeadLetter::create([
            'provider_key' => 'stripe',
            'event_id' => 'evt_resolved_1',
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['test' => true],
            'error_message' => 'Processing failed',
            'failed_at' => now(),
            'status' => 'resolved',
        ]);

        BillingWebhookDeadLetter::create([
            'provider_key' => 'stripe',
            'event_id' => 'evt_pending_1',
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['test' => true],
            'error_message' => 'Processing failed',
            'failed_at' => now(),
            'status' => 'pending',
        ]);

        // Only 1 pending → below threshold of 3
        $this->artisan('billing:check-dlq', ['--threshold' => 3])
            ->assertExitCode(0);
    }

    public function test_dlq_empty_queue_returns_success(): void
    {
        $this->artisan('billing:check-dlq')
            ->assertExitCode(0);
    }

    public function test_command_implements_isolatable(): void
    {
        $command = new \App\Console\Commands\BillingCheckDlqCommand();
        $this->assertInstanceOf(\Illuminate\Contracts\Console\Isolatable::class, $command);
    }
}
