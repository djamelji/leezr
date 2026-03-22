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

    public function test_owner_sees_4_onboarding_steps(): void
    {
        $response = $this->actAs($this->owner)->getJson('/api/dashboard/onboarding');

        $response->assertOk();
        $this->assertCount(4, $response->json('steps'));
        $this->assertEquals(4, $response->json('total_count'));

        $keys = collect($response->json('steps'))->pluck('key')->all();
        $this->assertContains('account_created', $keys);
        $this->assertContains('company_profile', $keys);
        $this->assertContains('payment_method', $keys);
        $this->assertContains('invite_member', $keys);
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

    public function test_dismissed_returns_dismissed_flag(): void
    {
        $this->company->update(['onboarding_dismissed_at' => now()]);

        $response = $this->actAs($this->owner)->getJson('/api/dashboard/onboarding');

        $response->assertOk();
        $response->assertJson(['dismissed' => true]);
        $this->assertArrayNotHasKey('steps', $response->json());
    }

    public function test_non_owner_cannot_dismiss(): void
    {
        $response = $this->actAs($this->dispatcher)->postJson('/api/dashboard/onboarding/dismiss');

        $response->assertForbidden();
        $this->assertNull($this->company->fresh()->onboarding_dismissed_at);
    }
}
