<?php

namespace Tests\Feature;

use App\Core\Billing\BillingJobHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ADR-631i (LOT 5) — every SCHEDULED billing automation must record a heartbeat so ops can verify it
 * actually ran (recovery status + Prometheus export). These four previously ran blind. On an empty DB
 * each completes with nothing to do, but MUST still leave a finished heartbeat.
 */
class BillingAutomationHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public static function commandProvider(): array
    {
        return [
            'expire-trials' => ['billing:expire-trials', 'billing:expire-trials'],
            'collect-scheduled' => ['billing:collect-scheduled', 'billing:collect-scheduled'],
            'check-trial-expiring' => ['billing:check-trial-expiring', 'billing:check-trial-expiring'],
            'check-expiring-cards' => ['billing:check-expiring-cards', 'billing:check-expiring-cards'],
        ];
    }

    /** @dataProvider commandProvider */
    public function test_scheduled_automation_records_a_finished_heartbeat(string $signature, string $jobKey): void
    {
        $this->assertNull(BillingJobHeartbeat::find($jobKey), 'no heartbeat before run');

        Artisan::call($signature);

        $hb = BillingJobHeartbeat::find($jobKey);
        $this->assertNotNull($hb, "command {$signature} must record a heartbeat");
        $this->assertNotNull($hb->last_finished_at, 'heartbeat must be finished (job completed)');
        $this->assertSame('ok', $hb->last_status);
    }

    public function test_dry_run_does_not_record_a_heartbeat(): void
    {
        Artisan::call('billing:expire-trials', ['--dry-run' => true]);

        $this->assertNull(BillingJobHeartbeat::find('billing:expire-trials'), 'dry-run must not fake a real run');
    }
}
