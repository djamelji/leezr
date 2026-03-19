<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-371: Manage-structure hardening tests.
 *
 * Verifies that:
 * - Dispatcher (is_administrative=true, no billing.manage) is blocked on ALL billing routes (ADR-373)
 * - Manager (is_administrative=true, has billing.manage) can access billing
 * - Owner (bypass) can access billing
 * - Driver (non-administrative) is blocked on billing and members
 * - Role visibility mutation requires roles.manage
 */
class ManageStructureHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $dispatcher;
    private User $driver;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Hardening Co',
            'slug' => 'hardening-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        // Enable all company modules
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // ── Manager: is_administrative=true, WITH billing.manage ──
        $managerRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'manager',
            'name' => 'Manager',
            'is_administrative' => true,
        ]);

        $managerRole->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        // ── Dispatcher: is_administrative=true (ADR-373), no billing.manage ──
        $dispatcherRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_administrative' => true,
        ]);

        $dispatcherPerms = CompanyPermission::whereIn('key', [
            'theme.view', 'theme.manage',
            'members.view', 'members.invite',
            'settings.view',
            'shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign', 'shipments.view_own',
            'support.view', 'support.create',
        ])->pluck('id')->toArray();

        $dispatcherRole->permissions()->sync($dispatcherPerms);

        // ── Driver: non-administrative, minimal permissions ──
        $driverRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'driver',
            'name' => 'Driver',
            'is_administrative' => false,
        ]);

        $driverPerms = CompanyPermission::whereIn('key', [
            'theme.view', 'theme.manage',
            'shipments.view_own', 'shipments.manage_status',
        ])->pluck('id')->toArray();

        $driverRole->permissions()->sync($driverPerms);

        // ── Users + Memberships ──
        $this->owner = User::factory()->create();
        $this->manager = User::factory()->create();
        $this->dispatcher = User::factory()->create();
        $this->driver = User::factory()->create();

        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company->memberships()->create(['user_id' => $this->manager->id, 'role' => 'user', 'company_role_id' => $managerRole->id]);
        $this->company->memberships()->create(['user_id' => $this->dispatcher->id, 'role' => 'user', 'company_role_id' => $dispatcherRole->id]);
        $this->company->memberships()->create(['user_id' => $this->driver->id, 'role' => 'user', 'company_role_id' => $driverRole->id]);
    }

    private function actAs(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    // ═══════════════════════════════════════════════════════
    // BILLING — Dispatcher BLOCKED on ALL routes
    // ═══════════════════════════════════════════════════════

    public function test_dispatcher_cannot_read_billing_overview(): void
    {
        $this->actAs($this->dispatcher)->getJson('/api/billing/overview')->assertStatus(403);
    }

    public function test_dispatcher_cannot_read_invoices(): void
    {
        $this->actAs($this->dispatcher)->getJson('/api/billing/invoices')->assertStatus(403);
    }

    public function test_dispatcher_cannot_read_saved_cards(): void
    {
        $this->actAs($this->dispatcher)->getJson('/api/billing/saved-cards')->assertStatus(403);
    }

    public function test_dispatcher_cannot_download_invoice_pdf(): void
    {
        $this->actAs($this->dispatcher)->getJson('/api/billing/invoices/1/pdf')->assertStatus(403);
    }

    public function test_dispatcher_cannot_checkout(): void
    {
        $this->actAs($this->dispatcher)->postJson('/api/billing/checkout', [])->assertStatus(403);
    }

    public function test_dispatcher_cannot_change_plan(): void
    {
        $this->actAs($this->dispatcher)->postJson('/api/billing/plan-change', [])->assertStatus(403);
    }

    public function test_dispatcher_cannot_cancel_subscription(): void
    {
        $this->actAs($this->dispatcher)->putJson('/api/billing/subscription/cancel', [])->assertStatus(403);
    }

    public function test_dispatcher_cannot_setup_payment_method(): void
    {
        $this->actAs($this->dispatcher)->postJson('/api/billing/setup-intent', [])->assertStatus(403);
    }

    public function test_dispatcher_cannot_retry_invoice(): void
    {
        $this->actAs($this->dispatcher)->postJson('/api/billing/invoices/1/retry', [])->assertStatus(403);
    }

    public function test_dispatcher_cannot_modify_role_visibility(): void
    {
        $this->actAs($this->dispatcher)->putJson('/api/theme/role-visibility', [])->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // BILLING — Manager CAN access (has billing.manage)
    // ═══════════════════════════════════════════════════════

    public function test_manager_can_read_billing_overview(): void
    {
        $this->actAs($this->manager)->getJson('/api/billing/overview')->assertOk();
    }

    // ═══════════════════════════════════════════════════════
    // BILLING — Owner CAN access (bypass)
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_read_billing_overview(): void
    {
        $this->actAs($this->owner)->getJson('/api/billing/overview')->assertOk();
    }

    // ═══════════════════════════════════════════════════════
    // DRIVER — not administrative, no billing
    // ═══════════════════════════════════════════════════════

    public function test_driver_cannot_access_billing(): void
    {
        $this->actAs($this->driver)->getJson('/api/billing/overview')->assertStatus(403);
    }

    public function test_driver_cannot_access_roles(): void
    {
        // Driver has no roles.view permission — roles route requires it
        $this->actAs($this->driver)->getJson('/api/company/roles')->assertStatus(403);
    }
}
