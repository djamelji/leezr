<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Billing\CompanyAddonSubscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-784 — differential security checks on surfaces touched by ADR-775 → 783.
 * Modules catalogue : readable by every member (features are gated on it) but contracted amounts and
 * paid subscriptions only for modules.manage / billing.manage ; never across companies.
 */
class Adr784SecurityTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $this->company = Company::create(['name' => 'Sec Co', 'slug' => 'sec-co-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->activateCompanyModules($this->company);
        $admin = $this->setUpCompanyRbac($this->company);
        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);
        CompanyAddonSubscription::create([
            'company_id' => $this->company->id, 'module_key' => 'logistics_fleet', 'amount_cents' => 1500,
            'currency' => 'EUR', 'interval' => 'monthly', 'activated_at' => now(),
        ]);
    }

    private function member(array $perms): User
    {
        $role = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'r-'.uniqid(), 'name' => 'R', 'is_administrative' => false]);
        $role->permissions()->sync(CompanyPermission::whereIn('key', $perms)->pluck('id')->all());
        $u = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $u->id, 'role' => 'user', 'company_role_id' => $role->id]);

        return $u;
    }

    private function modules(User $u, ?Company $c = null)
    {
        return $this->actingAs($u)->withHeaders(['X-Company-Id' => ($c ?? $this->company)->id])->getJson('/api/modules');
    }

    public function test_a_plain_member_reads_the_catalogue_without_billing_amounts(): void
    {
        $driver = $this->member(['shipments.view_own']);
        $res = $this->modules($driver)->assertOk();
        $this->assertNotEmpty($res->json('modules'), 'catalogue still served (features gate on it)');
        $this->assertSame([], $res->json('addon_subscriptions'));
        $this->assertSame([], collect($res->json('modules'))->pluck('contracted_amount_cents')->filter()->values()->all());
        $this->assertStringNotContainsString('1500', json_encode($res->json('addon_subscriptions')));
    }

    public function test_owner_and_billing_roles_see_subscriptions(): void
    {
        $this->assertSame(1500, $this->modules($this->owner)->assertOk()->json('addon_subscriptions.0.amount_cents'));
        $this->assertSame(1500, $this->modules($this->member(['billing.manage']))->json('addon_subscriptions.0.amount_cents'));
        $this->assertSame(1500, $this->modules($this->member(['modules.manage']))->json('addon_subscriptions.0.amount_cents'));
    }

    public function test_another_company_never_sees_these_subscriptions(): void
    {
        $other = Company::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->activateCompanyModules($other);
        $admin = $this->setUpCompanyRbac($other);
        $otherOwner = User::factory()->create();
        $other->memberships()->create(['user_id' => $otherOwner->id, 'role' => 'owner', 'company_role_id' => $admin->id]);

        $this->assertSame([], $this->modules($otherOwner, $other)->assertOk()->json('addon_subscriptions'));
        // and a member of the first company cannot read it through the other company's context
        $this->modules($this->owner, $other)->assertForbidden();
    }
}
