<?php

namespace Tests\Feature;

use App\Core\Billing\BillingJobHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recette finale (LOT 5) — a heartbeat must let ops DISTINGUISH three states, not just "ran or not":
 *   (a) success   → last_finished_at set + last_status='ok'
 *   (b) failure   → last_status='alert'/'failed' + last_error, OR started-but-never-finished (crash)
 *   (c) absence   → no heartbeat row at all (never executed)
 * These map 1:1 to what PlatformBillingController::recoveryStatus exposes to the UI.
 */
class BillingHeartbeatStatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_absence_is_distinguishable(): void
    {
        $this->assertNull(BillingJobHeartbeat::find('billing:renew'), 'no row → never executed');
    }

    public function test_success_state(): void
    {
        BillingJobHeartbeat::start('billing:renew');
        BillingJobHeartbeat::finish('billing:renew', 'ok', ['renewed' => 3]);

        $hb = BillingJobHeartbeat::find('billing:renew');
        $this->assertNotNull($hb->last_finished_at, 'finished');
        $this->assertSame('ok', $hb->last_status);
        $this->assertNull($hb->last_error);
        $this->assertSame(3, $hb->last_run_stats['renewed']);
    }

    public function test_failure_state_carries_error(): void
    {
        BillingJobHeartbeat::start('billing:process-dunning');
        BillingJobHeartbeat::finish('billing:process-dunning', 'failed', null, 'gateway timeout');

        $hb = BillingJobHeartbeat::find('billing:process-dunning');
        $this->assertSame('failed', $hb->last_status, 'failure is NOT reported as ok');
        $this->assertSame('gateway timeout', $hb->last_error);
    }

    public function test_crashed_run_started_but_never_finished(): void
    {
        // start() clears last_finished_at semantics: a run that crashed mid-way leaves started>finished.
        BillingJobHeartbeat::updateOrCreate(
            ['job_key' => 'billing:collect-scheduled'],
            ['last_started_at' => now(), 'last_finished_at' => now()->subHour(), 'last_status' => null],
        );

        $hb = BillingJobHeartbeat::find('billing:collect-scheduled');
        // started_at AFTER finished_at + null status ⇒ a run began and did not complete (crash/hang).
        $this->assertTrue($hb->last_started_at->greaterThan($hb->last_finished_at), 'crash detectable: started > finished');
        $this->assertNull($hb->last_status);
    }
}
