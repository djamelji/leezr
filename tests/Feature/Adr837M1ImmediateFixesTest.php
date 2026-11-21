<?php

namespace Tests\Feature;

use App\Core\Audit\CompanyAuditLog;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Billing\Subscription;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * ADR-837 (Leezr 4.2 M1) — UXC-01 billing history route, UXP-01 plan change from the company record only
 * (interval kept, policy timing = the previewed one, audited, manage_companies required).
 */
class Adr837M1ImmediateFixesTest extends TestCase
{
    use ActivatesCompanyModules;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();
        PlatformPermissionCatalog::sync();
        Carbon::setTestNow(Carbon::parse('2026-03-16'));

        $this->owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Annual Co', 'slug' => 'annual-co', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->activateCompanyModules($this->company);
        Subscription::create([
            'company_id' => $this->company->id, 'plan_key' => 'pro', 'interval' => 'yearly', 'status' => 'active',
            'current_period_start' => Carbon::parse('2026-01-01'), 'current_period_end' => Carbon::parse('2026-12-31'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function operator(array $permissions): PlatformUser
    {
        $user = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'M1', 'email' => Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'r_'.Str::random(6), 'name' => 'Role '.Str::random(4)]);
        $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
        $user->roles()->attach($role);

        return $user;
    }

    // ── UXC-01 ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_billing_timeline_route_returns_the_company_history_only(): void
    {
        CompanyAuditLog::create(['company_id' => $this->company->id, 'action' => 'subscription.plan_change_requested', 'severity' => 'info', 'metadata' => ['to_plan' => 'business'], 'created_at' => now()]);
        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        CompanyAuditLog::create(['company_id' => $other->id, 'action' => 'billing.refund', 'severity' => 'info', 'created_at' => now()]);

        $events = $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/billing/timeline')->assertOk()->json('events');
        $this->assertSame(['subscription.plan_change_requested'], array_column($events, 'action'));

        $this->getJson('/api/company/billing/timeline')->assertNotFound(); // the former (wrong) frontend path
    }

    public function test_billing_timeline_requires_billing_manage(): void
    {
        $member = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $member->id, 'role' => 'user']);
        $this->actingAs($member)->withHeaders(['X-Company-Id' => $this->company->id])->getJson('/api/billing/timeline')->assertForbidden();
    }

    // ── UXP-01 ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_annual_company_stays_annual_when_the_operator_does_not_choose_an_interval(): void
    {
        $op = $this->operator(['view_companies', 'manage_companies']);

        $preview = $this->actingAs($op, 'platform')->getJson("/api/platform/companies/{$this->company->id}/plan-preview?plan_key=business")->assertOk()->json();
        $this->assertSame('yearly', $preview['to_plan']['interval'] ?? null, 'the preview keeps the current (yearly) interval');

        $intent = $this->putJson("/api/platform/companies/{$this->company->id}/plan", ['plan_key' => 'business'])->assertOk()->json('intent');
        $this->assertSame('yearly', $intent['interval_to']);
        $this->assertSame($preview['timing'], $intent['timing'], 'the executed timing is the previewed one');
        if ($intent['status'] === 'executed') {
            $this->assertSame('yearly', Subscription::where('company_id', $this->company->id)->latest('id')->value('interval'));
        }
    }

    public function test_plan_change_is_audited_on_both_sides_and_same_plan_is_refused(): void
    {
        $op = $this->operator(['view_companies', 'manage_companies']);
        $this->actingAs($op, 'platform')->putJson("/api/platform/companies/{$this->company->id}/plan", ['plan_key' => 'pro'])
            ->assertUnprocessable(); // already on pro / yearly

        $this->putJson("/api/platform/companies/{$this->company->id}/plan", ['plan_key' => 'business'])->assertOk();
        $platform = PlatformAuditLog::where('action', 'subscription.plan_change_requested')->where('target_id', (string) $this->company->id)->sole();
        $this->assertSame(['plan_key' => 'pro', 'interval' => 'yearly'], $platform->diff_before);
        $this->assertSame(['plan_key' => 'business', 'interval' => 'yearly'], $platform->diff_after);
        $this->assertSame('platform', $platform->metadata['source']);
        $this->assertTrue(CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'subscription.plan_change_requested')->exists(),
            'visible in the company billing history');
    }

    public function test_operator_without_manage_companies_cannot_change_the_plan(): void
    {
        $reader = $this->operator(['view_companies']);
        $this->actingAs($reader, 'platform')->putJson("/api/platform/companies/{$this->company->id}/plan", ['plan_key' => 'business'])->assertForbidden();
        $this->assertSame('pro', Subscription::where('company_id', $this->company->id)->latest('id')->value('plan_key'));
    }

    public function test_the_companies_list_no_longer_changes_the_plan(): void
    {
        $list = file_get_contents(resource_path('js/pages/platform/companies/_CompaniesTab.vue'));
        $this->assertStringNotContainsString('changePlan', $list);
        $this->assertStringNotContainsString('updateCompanyPlan', $list);
        $store = file_get_contents(resource_path('js/modules/platform-admin/companies/companies.store.js'));
        $this->assertStringNotContainsString("interval = 'monthly'", $store, 'no silent monthly default');
    }
}
