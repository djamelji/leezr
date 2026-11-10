<?php

namespace Tests\Feature;

use App\Core\Alerts\PlatformAlert;
use App\Core\Billing\Invoice;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-749 (P0 — security). The cockpit endpoints /dashboard/attention and /dashboard/health
 * sit behind a single coarse `view_dashboard` gate but aggregate data from several sensitivity
 * domains. Each slice must be gated INSIDE the controller against its fine-grained permission,
 * mirroring the widget engine. These tests assert the DENIAL direction (a user never receives a
 * domain they are not authorised to read) AND the presence direction, plus the metric fixes.
 */
class DashboardCockpitPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
        $this->seedAttentionData();
    }

    private function userWith(array $permissionKeys): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissionKeys)->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'P', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    private function company(string $name): Company
    {
        return Company::create(['name' => $name, 'slug' => 'c-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
    }

    /** Create at least one row per attention domain so filtering is observable. */
    private function seedAttentionData(): void
    {
        $co = $this->company('Cockpit Co');

        // Billing — genuinely overdue (unpaid, past due > 7d)
        Invoice::create(['company_id' => $co->id, 'number' => 'INV-OD-1', 'subtotal' => 1000, 'tax_amount' => 200, 'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 1200, 'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'open', 'issued_at' => now()->subMonth(), 'finalized_at' => now()->subMonth(), 'due_at' => now()->subDays(20)]);
        // Billing — a PAID invoice left past due: must be EXCLUDED by the paid_at guard
        Invoice::create(['company_id' => $co->id, 'number' => 'INV-PAID-1', 'subtotal' => 1000, 'tax_amount' => 200, 'tax_rate_bps' => 2000, 'amount' => 1200, 'amount_due' => 0, 'wallet_credit_applied' => 0, 'currency' => 'EUR', 'status' => 'open', 'issued_at' => now()->subMonth(), 'finalized_at' => now()->subMonth(), 'due_at' => now()->subDays(20), 'paid_at' => now()->subDay()]);

        // Billing — subscription awaiting approval vs awaiting payment (distinct items).
        // Separate companies: subscriptions carry a UNIQUE(company_id, is_current) constraint.
        $co2 = $this->company('Cockpit Co 2');
        Subscription::create(['company_id' => $co->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'pending', 'provider' => 'internal', 'is_current' => false, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        Subscription::create(['company_id' => $co2->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'pending_payment', 'provider' => 'internal', 'is_current' => false, 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);

        // Support — unassigned ticket older than 24h
        $author = User::create(['name' => 'Author', 'first_name' => 'A', 'last_name' => 'U', 'email' => uniqid().'@co.com', 'password' => 'x']);
        $ticket = SupportTicket::create(['company_id' => $co->id, 'created_by_user_id' => $author->id, 'subject' => 'Help', 'status' => 'open', 'priority' => 'normal']);
        $ticket->forceFill(['created_at' => now()->subDays(2), 'assigned_to_platform_user_id' => null])->save();

        // Platform alert — active + critical
        PlatformAlert::create(['source' => 'test', 'type' => 'test.alert', 'severity' => 'critical', 'status' => 'active', 'title' => 'Boom', 'fingerprint' => 'fp-'.uniqid()]);
    }

    private function attention(PlatformUser $u): array
    {
        $res = $this->actingAs($u, 'platform')->getJson('/api/platform/dashboard/attention')->assertOk()->json('items');

        return collect($res)->pluck('type')->all();
    }

    private function health(PlatformUser $u): array
    {
        $res = $this->actingAs($u, 'platform')->getJson('/api/platform/dashboard/health')->assertOk()->json('badges');

        return array_keys($res ?? []);
    }

    // ── DENIAL direction (security-critical) ──

    public function test_dashboard_only_user_sees_no_attention_and_no_health(): void
    {
        $u = $this->userWith(['view_dashboard']);
        $this->assertSame([], $this->attention($u), 'view_dashboard alone must leak no attention items');
        $this->assertSame([], $this->health($u), 'view_dashboard alone must leak no health badges');
    }

    public function test_support_user_sees_no_billing_data(): void
    {
        $types = $this->attention($this->userWith(['view_dashboard', 'manage_support']));
        $this->assertContains('tickets_unassigned', $types);
        $this->assertNotContains('invoices_overdue', $types);
        $this->assertNotContains('subscriptions_pending', $types);
        $this->assertNotContains('subscriptions_pending_payment', $types);
        $this->assertNotContains('payments_failed', $types);
    }

    public function test_finance_user_sees_no_ops_or_security_data(): void
    {
        $u = $this->userWith(['view_dashboard', 'view_billing']);
        $types = $this->attention($u);
        $this->assertContains('invoices_overdue', $types);
        $this->assertNotContains('tickets_unassigned', $types);
        $this->assertNotContains('alerts_critical', $types);
        // health: no system_health, no alerts permission → empty badges
        $this->assertSame([], $this->health($u));
    }

    public function test_alerts_require_view_alerts_permission(): void
    {
        $this->assertNotContains('alerts_critical', $this->attention($this->userWith(['view_dashboard', 'view_billing'])));
        $this->assertContains('alerts_critical', $this->attention($this->userWith(['view_dashboard', 'view_alerts'])));
    }

    public function test_health_badges_require_view_system_health(): void
    {
        $withHealth = $this->health($this->userWith(['view_dashboard', 'view_system_health']));
        $this->assertContains('scheduler', $withHealth);
        $this->assertContains('queue', $withHealth);
        $this->assertContains('ai', $withHealth);
        $this->assertNotContains('alerts', $withHealth, 'alerts badge needs view_alerts, not view_system_health');

        $withAlerts = $this->health($this->userWith(['view_dashboard', 'view_alerts']));
        $this->assertContains('alerts', $withAlerts);
        $this->assertNotContains('scheduler', $withAlerts);
    }

    // ── Metric correctness ──

    public function test_overdue_invoices_exclude_paid(): void
    {
        $items = $this->actingAs($this->userWith(['view_dashboard', 'view_billing']), 'platform')
            ->getJson('/api/platform/dashboard/attention')->assertOk()->json('items');
        $overdue = collect($items)->firstWhere('type', 'invoices_overdue');
        $this->assertNotNull($overdue);
        $this->assertSame(1, $overdue['count'], 'paid-but-past-due invoice must be excluded from overdue count');
    }

    public function test_pending_payment_surfaced_distinctly(): void
    {
        $types = $this->attention($this->userWith(['view_dashboard', 'view_billing']));
        $this->assertContains('subscriptions_pending', $types);
        $this->assertContains('subscriptions_pending_payment', $types);
    }

    public function test_scheduler_not_critical_when_merely_idle(): void
    {
        // No heartbeat + no runs in the test DB → must be 'warning' (detectable), NOT 'critical'.
        $badges = $this->actingAs($this->userWith(['view_dashboard', 'view_system_health']), 'platform')
            ->getJson('/api/platform/dashboard/health')->assertOk()->json('badges');
        $this->assertNotSame('critical', $badges['scheduler']['status'], 'idle scheduler must not be flagged critical');
        $this->assertContains($badges['scheduler']['status'], ['ok', 'warning', 'unknown']);
    }
}
