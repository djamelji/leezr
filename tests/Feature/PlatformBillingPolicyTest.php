<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Modules\ModuleRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBillingPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->admin = PlatformUser::create([
            'first_name' => 'Billing',
            'last_name' => 'Admin',
            'email' => 'billing-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ─── Auth / Permission ────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/platform/billing/billing-policy')
            ->assertStatus(401);
    }

    public function test_viewer_cannot_update(): void
    {
        $viewer = PlatformUser::create([
            'first_name' => 'View',
            'last_name' => 'Only',
            'email' => 'viewer@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $viewerRole = PlatformRole::where('key', 'viewer')->first();

        if ($viewerRole) {
            $viewer->roles()->attach($viewerRole);
        }

        $this->actingAs($viewer, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['grace_period_days' => 5])
            ->assertStatus(403);
    }

    // ─── GET ──────────────────────────────────────────────────────

    public function test_show_returns_policy_singleton(): void
    {
        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/billing/billing-policy');

        $response->assertOk()
            ->assertJsonStructure([
                'policy' => [
                    'id',
                    'allow_negative_wallet',
                    'auto_apply_wallet_credit',
                    'upgrade_timing',
                    'downgrade_timing',
                    'proration_strategy',
                    'grace_period_days',
                    'max_retry_attempts',
                    'retry_intervals_days',
                    'failure_action',
                    'invoice_due_days',
                    'invoice_prefix',
                    'invoice_next_number',
                    'credit_note_prefix',
                    'credit_note_next_number',
                    'tax_mode',
                    'default_tax_rate_bps',
                    'addon_billing_interval',
                    'trial_plan_change_behavior',
                ],
            ]);
    }

    // ─── Enum validation ──────────────────────────────────────────

    public function test_rejects_invalid_upgrade_timing(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['upgrade_timing' => 'next_month'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upgrade_timing']);
    }

    public function test_rejects_invalid_downgrade_timing(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['downgrade_timing' => 'next_month'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['downgrade_timing']);
    }

    public function test_rejects_invalid_proration_strategy(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['proration_strategy' => 'hourly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['proration_strategy']);
    }

    public function test_rejects_invalid_tax_mode(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['tax_mode' => 'hybrid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tax_mode']);
    }

    public function test_rejects_invalid_failure_action(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['failure_action' => 'delete_company'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['failure_action']);
    }

    public function test_rejects_invalid_addon_billing_interval(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['addon_billing_interval' => 'weekly'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['addon_billing_interval']);
    }

    // ─── Bounds validation ────────────────────────────────────────

    public function test_rejects_negative_grace_period(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['grace_period_days' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grace_period_days']);
    }

    public function test_rejects_negative_max_retry_attempts(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['max_retry_attempts' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_retry_attempts']);
    }

    public function test_rejects_negative_invoice_due_days(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['invoice_due_days' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_due_days']);
    }

    public function test_rejects_tax_rate_above_10000(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['default_tax_rate_bps' => 10001])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_tax_rate_bps']);
    }

    public function test_accepts_zero_tax_rate(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['default_tax_rate_bps' => 0])
            ->assertOk();
    }

    public function test_accepts_max_tax_rate(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['default_tax_rate_bps' => 10000])
            ->assertOk();
    }

    // ─── retry_intervals_days constraints ─────────────────────────

    public function test_retry_intervals_must_match_max_retry_attempts(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [
                'max_retry_attempts' => 3,
                'retry_intervals_days' => [1, 3],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.retry_intervals_days.0', 'Expected 3 entries, got 2.');
    }

    public function test_retry_intervals_must_be_strictly_increasing(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [
                'max_retry_attempts' => 3,
                'retry_intervals_days' => [1, 3, 2],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.retry_intervals_days.0', 'Values must be strictly increasing.');
    }

    public function test_retry_intervals_rejects_equal_values(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [
                'max_retry_attempts' => 3,
                'retry_intervals_days' => [1, 3, 3],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.retry_intervals_days.0', 'Values must be strictly increasing.');
    }

    public function test_retry_intervals_valid(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [
                'max_retry_attempts' => 4,
                'retry_intervals_days' => [1, 3, 7, 14],
            ])
            ->assertOk()
            ->assertJsonPath('policy.retry_intervals_days', [1, 3, 7, 14])
            ->assertJsonPath('policy.max_retry_attempts', 4);
    }

    public function test_changing_only_max_retry_validates_against_existing_intervals(): void
    {
        // Default is max_retry_attempts=3, retry_intervals_days=[1,3,7]
        PlatformBillingPolicy::instance();

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [
                'max_retry_attempts' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.retry_intervals_days.0', 'Expected 5 entries, got 3.');
    }

    // ─── next_number cannot decrease ──────────────────────────────

    public function test_invoice_next_number_cannot_decrease(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['invoice_next_number' => 100]);

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['invoice_next_number' => 50])
            ->assertStatus(422)
            ->assertJsonPath('errors.invoice_next_number.0', 'Cannot decrease below current value (100).');
    }

    public function test_invoice_next_number_can_increase(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['invoice_next_number' => 100]);

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['invoice_next_number' => 200])
            ->assertOk()
            ->assertJsonPath('policy.invoice_next_number', 200);
    }

    public function test_credit_note_next_number_cannot_decrease(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['credit_note_next_number' => 50]);

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['credit_note_next_number' => 10])
            ->assertStatus(422)
            ->assertJsonPath('errors.credit_note_next_number.0', 'Cannot decrease below current value (50).');
    }

    // ─── Prefix format validation ─────────────────────────────────

    public function test_rejects_invalid_invoice_prefix(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['invoice_prefix' => 'inv!@#'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_prefix']);
    }

    public function test_accepts_valid_invoice_prefix(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['invoice_prefix' => 'INV-2026'])
            ->assertOk()
            ->assertJsonPath('policy.invoice_prefix', 'INV-2026');
    }

    // ─── Audit log ────────────────────────────────────────────────

    public function test_update_creates_audit_log_with_diff(): void
    {
        PlatformBillingPolicy::instance();

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [
                'grace_period_days' => 7,
                'tax_mode' => 'exclusive',
            ])
            ->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => AuditAction::BILLING_POLICY_UPDATED,
            'target_type' => 'platform_billing_policy',
        ]);
    }

    public function test_no_change_skips_audit(): void
    {
        $policy = PlatformBillingPolicy::instance();

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [
                'grace_period_days' => $policy->grace_period_days,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('platform_audit_logs', [
            'action' => AuditAction::BILLING_POLICY_UPDATED,
        ]);
    }

    // ─── Empty payload ────────────────────────────────────────────

    public function test_empty_payload_returns_422(): void
    {
        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', [])
            ->assertStatus(422);
    }

    // ─── Singleton atomicity ──────────────────────────────────────

    public function test_consecutive_updates_are_atomic(): void
    {
        PlatformBillingPolicy::instance();

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['grace_period_days' => 5])
            ->assertOk();

        $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', ['invoice_due_days' => 14])
            ->assertOk();

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/billing/billing-policy');

        $response->assertOk()
            ->assertJsonPath('policy.grace_period_days', 5)
            ->assertJsonPath('policy.invoice_due_days', 14);

        // Still only one row
        $this->assertSame(1, PlatformBillingPolicy::query()->count());
    }

    // ─── Valid full update ────────────────────────────────────────

    public function test_valid_full_update(): void
    {
        PlatformBillingPolicy::instance();

        $payload = [
            'allow_negative_wallet' => true,
            'auto_apply_wallet_credit' => false,
            'upgrade_timing' => 'end_of_period',
            'downgrade_timing' => 'end_of_period',
            'proration_strategy' => 'none',
            'grace_period_days' => 7,
            'max_retry_attempts' => 4,
            'retry_intervals_days' => [1, 3, 7, 14],
            'failure_action' => 'read_only',
            'invoice_due_days' => 15,
            'invoice_prefix' => 'FAC',
            'tax_mode' => 'inclusive',
            'default_tax_rate_bps' => 2000,
            'addon_billing_interval' => 'monthly',
        ];

        $response = $this->actingAs($this->admin, 'platform')
            ->putJson('/api/platform/billing/billing-policy', $payload);

        $response->assertOk();

        foreach ($payload as $key => $value) {
            $response->assertJsonPath("policy.{$key}", $value);
        }
    }
}
