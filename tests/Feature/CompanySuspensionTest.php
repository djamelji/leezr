<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Billing\Dunning\DunningNotifier;
use App\Core\Billing\Dunning\DunningTransitioner;
use App\Core\Billing\Invoice;
use App\Core\Billing\InvoiceIssuer;
use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Notifications\NotificationEvent;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Company Suspension E2E tests.
 *
 * Covers:
 *   1. Platform admin can suspend an active company
 *   2. Suspend endpoint creates audit log entry
 *   3. Suspended company cannot access protected company routes
 *   4. Suspended company CAN access billing payment routes (ADR-257 bypass)
 *   5. Platform admin can reactivate a suspended company
 *   6. Reactivate endpoint creates audit log entry
 *   7. Company status persisted correctly after suspend
 *   8. Company status persisted correctly after reactivate
 *   9. Cannot suspend a non-existent company (404)
 *  10. Non-admin cannot suspend a company (401)
 *  11. Automatic suspension via dunning (failure_action=suspend)
 *  12. Automatic suspension via dunning (failure_action=cancel) also suspends company
 *  13. Suspension is idempotent (dunning does not duplicate notification)
 *  14. Reactivation after paying all overdue invoices
 *  15. Data preservation: subscription and company data intact during suspension
 */
class CompanySuspensionTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;
    private Company $company;
    private User $owner;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        // Platform admin with super_admin role
        $this->admin = PlatformUser::create([
            'first_name' => 'Suspend',
            'last_name' => 'Admin',
            'email' => 'suspend-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);

        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);

        // Company with owner and subscription
        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Suspension Test Co',
            'slug' => 'suspension-test-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_key' => 'pro',
            'interval' => 'monthly',
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);
    }

    private function actAsPlatform(): static
    {
        return $this->actingAs($this->admin, 'platform');
    }

    private function actAsCompanyOwner(): static
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // 1. Manual suspension by platform admin
    // ═══════════════════════════════════════════════════════

    public function test_platform_admin_can_suspend_active_company(): void
    {
        $response = $this->actAsPlatform()
            ->putJson("/api/platform/companies/{$this->company->id}/suspend");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Company suspended.']);

        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);
    }

    // ═══════════════════════════════════════════════════════
    // 2. Suspend creates audit log
    // ═══════════════════════════════════════════════════════

    public function test_suspend_creates_audit_log_entry(): void
    {
        $this->actAsPlatform()
            ->putJson("/api/platform/companies/{$this->company->id}/suspend")
            ->assertOk();

        $log = PlatformAuditLog::where('action', AuditAction::COMPANY_SUSPENDED)
            ->where('target_type', 'company')
            ->where('target_id', (string) $this->company->id)
            ->first();

        $this->assertNotNull($log, 'Audit log entry for company suspension should exist');
    }

    // ═══════════════════════════════════════════════════════
    // 3. Suspended company cannot access protected routes
    // ═══════════════════════════════════════════════════════

    public function test_suspended_company_cannot_access_protected_routes(): void
    {
        $this->company->update(['status' => 'suspended']);

        $this->actAsCompanyOwner()
            ->getJson('/api/nav')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'This company is currently suspended.']);
    }

    // ═══════════════════════════════════════════════════════
    // 4. Suspended company CAN access billing payment routes (ADR-257)
    // ═══════════════════════════════════════════════════════

    public function test_suspended_company_can_access_billing_outstanding_route(): void
    {
        $this->company->update(['status' => 'suspended']);

        // The billing/invoices/outstanding route should NOT be blocked by suspension
        // (ADR-257: suspended companies must be able to pay their invoices)
        $response = $this->actAsCompanyOwner()
            ->getJson('/api/billing/invoices/outstanding');

        // Should NOT get 403 "suspended" — may get 200 or another status, but not the suspension block
        $this->assertNotEquals(403, $response->status(),
            'Billing outstanding route should bypass suspension check (ADR-257)');
    }

    // ═══════════════════════════════════════════════════════
    // 5. Platform admin can reactivate a suspended company
    // ═══════════════════════════════════════════════════════

    public function test_platform_admin_can_reactivate_suspended_company(): void
    {
        $this->company->update(['status' => 'suspended']);

        $response = $this->actAsPlatform()
            ->putJson("/api/platform/companies/{$this->company->id}/reactivate");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Company reactivated.']);

        $this->company->refresh();
        $this->assertEquals('active', $this->company->status);
    }

    // ═══════════════════════════════════════════════════════
    // 6. Reactivate creates audit log
    // ═══════════════════════════════════════════════════════

    public function test_reactivate_creates_audit_log_entry(): void
    {
        $this->company->update(['status' => 'suspended']);

        $this->actAsPlatform()
            ->putJson("/api/platform/companies/{$this->company->id}/reactivate")
            ->assertOk();

        $log = PlatformAuditLog::where('action', AuditAction::COMPANY_REACTIVATED)
            ->where('target_type', 'company')
            ->where('target_id', (string) $this->company->id)
            ->first();

        $this->assertNotNull($log, 'Audit log entry for company reactivation should exist');
    }

    // ═══════════════════════════════════════════════════════
    // 7. Company status persisted correctly after suspend
    // ═══════════════════════════════════════════════════════

    public function test_company_status_persisted_as_suspended_in_database(): void
    {
        $this->actAsPlatform()
            ->putJson("/api/platform/companies/{$this->company->id}/suspend")
            ->assertOk();

        // Verify directly from DB (not cached model)
        $dbStatus = Company::where('id', $this->company->id)->value('status');
        $this->assertEquals('suspended', $dbStatus);

        // Verify model helper
        $company = Company::find($this->company->id);
        $this->assertTrue($company->isSuspended());
        $this->assertFalse($company->isActive());
    }

    // ═══════════════════════════════════════════════════════
    // 8. Company status persisted correctly after reactivate
    // ═══════════════════════════════════════════════════════

    public function test_company_status_persisted_as_active_after_reactivation(): void
    {
        $this->company->update(['status' => 'suspended']);

        $this->actAsPlatform()
            ->putJson("/api/platform/companies/{$this->company->id}/reactivate")
            ->assertOk();

        $dbStatus = Company::where('id', $this->company->id)->value('status');
        $this->assertEquals('active', $dbStatus);

        $company = Company::find($this->company->id);
        $this->assertTrue($company->isActive());
        $this->assertFalse($company->isSuspended());
    }

    // ═══════════════════════════════════════════════════════
    // 9. Cannot suspend a non-existent company
    // ═══════════════════════════════════════════════════════

    public function test_suspend_nonexistent_company_returns_404(): void
    {
        $this->actAsPlatform()
            ->putJson('/api/platform/companies/99999/suspend')
            ->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════
    // 10. Non-admin cannot suspend a company
    // ═══════════════════════════════════════════════════════

    public function test_unauthenticated_user_cannot_suspend_company(): void
    {
        $this->putJson("/api/platform/companies/{$this->company->id}/suspend")
            ->assertUnauthorized();
    }

    // ═══════════════════════════════════════════════════════
    // 11. Automatic suspension via dunning (failure_action=suspend)
    // ═══════════════════════════════════════════════════════

    public function test_dunning_failure_action_suspend_suspends_company_and_subscription(): void
    {
        Notification::fake();

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['failure_action' => 'suspend']);

        DunningTransitioner::applyFailureAction($this->company, $policy);

        $this->company->refresh();
        $this->subscription->refresh();

        $this->assertEquals('suspended', $this->company->status);
        $this->assertEquals('suspended', $this->subscription->status);
        $this->assertNull($this->subscription->is_current);
    }

    // ═══════════════════════════════════════════════════════
    // 12. Automatic suspension via dunning (failure_action=cancel)
    // ═══════════════════════════════════════════════════════

    public function test_dunning_failure_action_cancel_suspends_company_and_downgrades_plan(): void
    {
        Notification::fake();

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['failure_action' => 'cancel']);

        DunningTransitioner::applyFailureAction($this->company, $policy);

        $this->company->refresh();
        $this->subscription->refresh();

        $this->assertEquals('suspended', $this->company->status);
        $this->assertEquals('cancelled', $this->subscription->status);
        $this->assertEquals('starter', $this->company->plan_key);
    }

    // ═══════════════════════════════════════════════════════
    // 13. Suspension is idempotent (no duplicate notification)
    // ═══════════════════════════════════════════════════════

    public function test_dunning_suspension_is_idempotent(): void
    {
        Notification::fake();

        $policy = PlatformBillingPolicy::instance();
        $policy->update(['failure_action' => 'suspend']);

        // First suspension
        DunningTransitioner::applyFailureAction($this->company, $policy);
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        // Count notifications created by first call
        $notifCountAfterFirst = NotificationEvent::where('company_id', $this->company->id)
            ->where('topic_key', 'billing.account_suspended')
            ->count();

        // Second suspension (should be idempotent — company already suspended)
        DunningTransitioner::applyFailureAction($this->company, $policy);
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);

        // Notification count should not increase
        $notifCountAfterSecond = NotificationEvent::where('company_id', $this->company->id)
            ->where('topic_key', 'billing.account_suspended')
            ->count();

        $this->assertEquals($notifCountAfterFirst, $notifCountAfterSecond,
            'Duplicate suspension should not create additional notification');
    }

    // ═══════════════════════════════════════════════════════
    // 14. Reactivation after paying all overdue invoices
    // ═══════════════════════════════════════════════════════

    public function test_reactivation_when_all_overdue_invoices_paid(): void
    {
        Notification::fake();

        // Suspend company and subscription
        $this->company->update(['status' => 'suspended']);
        $this->subscription->update(['status' => 'past_due']);

        // Create an overdue invoice, then mark it paid
        $invoice = InvoiceIssuer::createDraft($this->company, $this->subscription->id);
        InvoiceIssuer::addLine($invoice, 'plan', 'Pro plan', 2900);
        $invoice = InvoiceIssuer::finalize($invoice);
        $invoice->update(['status' => 'overdue']);

        // Simulate paying it
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);

        // Trigger reactivation check
        DunningTransitioner::checkReactivation($this->company, $this->subscription->id);

        $this->company->refresh();
        $this->subscription->refresh();

        $this->assertEquals('active', $this->company->status);
        $this->assertEquals('active', $this->subscription->status);
    }

    // ═══════════════════════════════════════════════════════
    // 15. Data preservation during suspension
    // ═══════════════════════════════════════════════════════

    public function test_company_data_preserved_during_suspension(): void
    {
        // Record original state
        $originalName = $this->company->name;
        $originalSlug = $this->company->slug;
        $originalPlanKey = $this->company->plan_key;
        $originalJobdomainKey = $this->company->jobdomain_key;
        $memberCount = $this->company->memberships()->count();

        // Suspend via platform admin
        $this->actAsPlatform()
            ->putJson("/api/platform/companies/{$this->company->id}/suspend")
            ->assertOk();

        // Verify all data is preserved
        $this->company->refresh();
        $this->assertEquals('suspended', $this->company->status);
        $this->assertEquals($originalName, $this->company->name);
        $this->assertEquals($originalSlug, $this->company->slug);
        $this->assertEquals($originalPlanKey, $this->company->plan_key);
        $this->assertEquals($originalJobdomainKey, $this->company->jobdomain_key);
        $this->assertEquals($memberCount, $this->company->memberships()->count());

        // Subscription still exists and retains its data
        $this->subscription->refresh();
        $this->assertEquals('pro', $this->subscription->plan_key);
        $this->assertEquals('monthly', $this->subscription->interval);
    }
}
