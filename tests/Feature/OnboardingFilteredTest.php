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
 * ADR-383: Onboarding widget — owner-only, dismissible, 4 steps.
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

    public function test_owner_sees_6_onboarding_steps(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/dashboard/onboarding');

        $response->assertOk();
        $this->assertCount(6, $response->json('steps'));
        $this->assertEquals(6, $response->json('total_count'));

        $keys = collect($response->json('steps'))->pluck('key')->all();
        $this->assertContains('account_created', $keys);
        $this->assertContains('company_profile', $keys);
        $this->assertContains('payment_method', $keys);
        $this->assertContains('invite_member', $keys);
        $this->assertContains('activate_module', $keys);
        $this->assertContains('first_document', $keys);
        $this->assertNotContains('plan_selected', $keys);
    }

    public function test_non_owner_gets_403(): void
    {
        $response = $this->actAs($this->dispatcher)->getJson('/api/dashboard/onboarding');

        $response->assertForbidden();
    }

    public function test_dismiss_sets_timestamp(): void
    {
        $this->assertNull($this->company->fresh()->onboarding_dismissed_at);

        $response = $this->actAs($this->owner)->postJson('/api/dashboard/onboarding/dismiss');

        $response->assertOk();
        $response->assertJson(['dismissed' => true]);
        $this->assertNotNull($this->company->fresh()->onboarding_dismissed_at);
    }

    public function test_dismissed_returns_dismissed_flag_with_steps(): void
    {
        $this->company->update(['onboarding_dismissed_at' => now()]);

        $response = $this->actAs($this->owner)->getJson('/api/dashboard/onboarding');

        $response->assertOk();
        $response->assertJson(['dismissed' => true]);
        // Dismissed response now includes steps so frontend can determine if reopen is possible
        $this->assertArrayHasKey('steps', $response->json());
        $this->assertArrayHasKey('completed_count', $response->json());
        $this->assertArrayHasKey('total_count', $response->json());
    }

    public function test_non_owner_cannot_dismiss(): void
    {
        $response = $this->actAs($this->dispatcher)->postJson('/api/dashboard/onboarding/dismiss');

        $response->assertForbidden();
        $this->assertNull($this->company->fresh()->onboarding_dismissed_at);
    }

    public function test_reopen_clears_dismissed_timestamp(): void
    {
        $this->company->update(['onboarding_dismissed_at' => now()]);
        $this->assertNotNull($this->company->fresh()->onboarding_dismissed_at);

        $response = $this->actAs($this->owner)->postJson('/api/dashboard/onboarding/reopen');

        $response->assertOk();
        $this->assertNull($this->company->fresh()->onboarding_dismissed_at);
        $this->assertArrayHasKey('steps', $response->json());
        $this->assertCount(6, $response->json('steps'));
        $this->assertArrayNotHasKey('dismissed', $response->json());
    }

    public function test_non_owner_cannot_reopen(): void
    {
        $this->company->update(['onboarding_dismissed_at' => now()]);

        $response = $this->actAs($this->dispatcher)->postJson('/api/dashboard/onboarding/reopen');

        $response->assertForbidden();
        $this->assertNotNull($this->company->fresh()->onboarding_dismissed_at);
    }
}
