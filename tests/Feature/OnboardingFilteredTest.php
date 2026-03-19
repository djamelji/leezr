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
 * ADR-372: Onboarding steps filtered by permissions.
 */
class OnboardingFilteredTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $dispatcher;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Onboarding Co',
            'slug' => 'onboarding-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // Dispatcher: no billing.manage, no settings.manage, has members.invite
        $dispatcherRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_administrative' => true, // ADR-373
        ]);

        $dispatcherPerms = CompanyPermission::whereIn('key', [
            'theme.view', 'theme.manage',
            'members.view', 'members.invite',
            'settings.view',
            'shipments.view', 'shipments.create', 'shipments.manage_status', 'shipments.assign', 'shipments.view_own',
        ])->pluck('id')->toArray();

        $dispatcherRole->permissions()->sync($dispatcherPerms);

        $this->owner = User::factory()->create();
        $this->dispatcher = User::factory()->create();

        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company->memberships()->create(['user_id' => $this->dispatcher->id, 'role' => 'user', 'company_role_id' => $dispatcherRole->id]);
    }

    private function actAs(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    public function test_owner_sees_all_5_onboarding_steps(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/dashboard/onboarding');

        $response->assertOk();
        $this->assertCount(5, $response->json('steps'));
        $this->assertEquals(5, $response->json('total_count'));
    }

    public function test_dispatcher_sees_only_2_onboarding_steps(): void
    {
        $response = $this->actAs($this->dispatcher)->getJson('/api/dashboard/onboarding');

        $response->assertOk();

        $stepKeys = collect($response->json('steps'))->pluck('key')->all();

        // Dispatcher has members.invite → sees invite_member + account_created (null perm)
        $this->assertContains('account_created', $stepKeys);
        $this->assertContains('invite_member', $stepKeys);

        // Dispatcher does NOT have billing.manage or settings.manage
        $this->assertNotContains('plan_selected', $stepKeys);
        $this->assertNotContains('payment_method', $stepKeys);
        $this->assertNotContains('company_profile', $stepKeys);

        $this->assertCount(2, $stepKeys);
    }
}
