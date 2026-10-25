<?php

namespace Tests\Feature;

use App\Core\Billing\BillingWebhookDeadLetter;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-631g (LOT 7D) — the dead-letter list exposes the full webhook payload + error so an admin can
 * diagnose a failed billing event from the UI, with no SQL on billing_webhook_dead_letters. This pins
 * the API contract the inspection dialog depends on.
 */
class DeadLetterPayloadExposedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function admin(): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['view_billing', 'manage_billing'])->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'A', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    public function test_dead_letter_list_exposes_payload_and_error(): void
    {
        BillingWebhookDeadLetter::create([
            'provider_key' => 'stripe', 'event_id' => 'evt_123', 'event_type' => 'invoice.payment_failed',
            'payload' => ['id' => 'evt_123', 'data' => ['amount' => 2900, 'currency' => 'eur']],
            'error_message' => 'Signature verification failed', 'failed_at' => now(),
            'status' => 'pending', 'replay_attempts' => 1,
        ]);

        $json = $this->actingAs($this->admin(), 'platform')
            ->getJson('/api/platform/billing/dead-letters')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('invoice.payment_failed', $json['event_type']);
        $this->assertSame('Signature verification failed', $json['error_message']);
        $this->assertSame('evt_123', $json['event_id']);
        // The full payload (the SQL-only data) must be present for UI diagnosis.
        $this->assertIsArray($json['payload']);
        $this->assertSame(2900, $json['payload']['data']['amount']);
    }
}
