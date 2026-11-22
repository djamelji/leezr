<?php

namespace Tests\Feature;

use App\Core\Audit\PlatformAuditLog;
use App\Core\Billing\PaymentRegistry;
use App\Core\Models\Company;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Modules\Dashboard\PlatformUserDashboardLayout;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-855 (Leezr 5.0 L1) — Platform cockpit V35 on the EXISTING dashboard engine.
 *  - a saved layout never brings back a widget the user may not see (read AND write filtered by permission) ;
 *  - the three new widgets read existing sources, behind the same permissions as their pages ;
 *  - companies_overview only gains keys ; size bounds were only relaxed (saved layouts stay valid).
 */
class Adr855PlatformCockpitTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    private PlatformUser $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();

        $this->admin = PlatformUser::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => 'admin855@test.com', 'password' => 'P@ssw0rd!Strong']);
        $this->admin->roles()->attach(PlatformRole::where('key', 'super_admin')->first());

        $this->support = PlatformUser::create(['first_name' => 'Sup', 'last_name' => 'Port', 'email' => 'support855@test.com', 'password' => 'P@ssw0rd!Strong']);
        $role = PlatformRole::create(['key' => 'support855', 'name' => 'Support 855']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['view_dashboard', 'manage_support', 'view_companies'])->pluck('id')->all());
        $this->support->roles()->attach($role);
    }

    private function tile(string $key, int $x, int $y, int $w = 3, int $h = 2): array
    {
        return ['key' => $key, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'scope' => 'global', 'config' => []];
    }

    public function test_a_saved_layout_never_serves_a_forbidden_widget(): void
    {
        // e.g. the role lost view_billing after the layout was saved
        PlatformUserDashboardLayout::create(['user_id' => $this->support->id, 'layout_json' => [
            $this->tile('billing.revenue_mtd', 0, 0), $this->tile('platform.commercial_overview', 3, 0, 8, 5), $this->tile('platform.support_overview', 0, 5),
        ]]);

        $keys = collect($this->actingAs($this->support, 'platform')->getJson('/api/platform/dashboard/layout')->assertOk()->json('layout'))->pluck('key')->all();

        $this->assertSame(['platform.support_overview'], $keys);
        // and the data endpoint still refuses it independently
        $this->actingAs($this->support, 'platform')->postJson('/api/platform/dashboard/widgets/data', ['widgets' => [['key' => 'platform.commercial_overview', 'scope' => 'global']]])
            ->assertOk()->assertJsonPath('results.0.error', 'forbidden');
    }

    public function test_saving_never_stores_a_forbidden_widget(): void
    {
        $this->actingAs($this->support, 'platform')->putJson('/api/platform/dashboard/layout', ['layout' => [
            $this->tile('platform.support_overview', 0, 0), $this->tile('billing.revenue_mtd', 3, 0),
        ]])->assertOk()->assertJsonCount(1, 'layout');

        $stored = PlatformUserDashboardLayout::where('user_id', $this->support->id)->value('layout_json');
        $this->assertSame(['platform.support_overview'], array_column($stored, 'key'));
    }

    public function test_new_widgets_follow_the_permissions_of_their_pages(): void
    {
        $catalog = fn (PlatformUser $u) => collect($this->actingAs($u, 'platform')->getJson('/api/platform/dashboard/widgets/catalog')->assertOk()->json('widgets'))->pluck('key')->all();

        $admin = $catalog($this->admin);
        foreach (['platform.signups', 'platform.trials_ending', 'platform.recent_activity'] as $k) {
            $this->assertContains($k, $admin);
        }

        $support = $catalog($this->support);
        $this->assertContains('platform.trials_ending', $support, 'view_companies');
        $this->assertNotContains('platform.signups', $support, 'view_usage_monitoring required (funnel page)');
        $this->assertNotContains('platform.recent_activity', $support, 'view_audit_logs required (journal)');
        $this->assertEmpty(array_filter($support, fn ($k) => str_starts_with($k, 'billing.') || in_array($k, ['platform.commercial_overview', 'platform.plan_distribution'], true)), 'no finance for Support');
    }

    public function test_trials_widget_lists_current_trials_soonest_first_with_expired_ones(): void
    {
        foreach ([['A', -3], ['B', 10], ['C', 2]] as [$n, $days]) {
            $c = Company::create(['name' => "Essai $n", 'slug' => "essai-855-$n", 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
            DB::table('subscriptions')->insert(['company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'trialing', 'is_current' => 1, 'trial_ends_at' => now()->addDays($days), 'created_at' => now(), 'updated_at' => now()]);
        }

        $d = DashboardWidgetRegistry::find('platform.trials_ending')->resolve([]);

        $this->assertSame(3, $d['total']);
        $this->assertSame(1, $d['ending_7d']);
        $this->assertSame(['Essai A', 'Essai C', 'Essai B'], array_column($d['items'], 'name'));
        $this->assertSame(-3, $d['items'][0]['days_left'], 'an expired trial still « trialing » is surfaced first');
    }

    public function test_activity_widget_hides_sign_ins_and_names_the_company(): void
    {
        $c = Company::create(['name' => 'Journal Co', 'slug' => 'journal-855', 'plan_key' => 'pro', 'jobdomain_key' => 'logistique']);
        PlatformAuditLog::create(['actor_id' => $this->admin->id, 'actor_type' => 'admin', 'action' => 'platform_auth.login', 'target_type' => 'admin', 'target_id' => (string) $this->admin->id, 'severity' => 'info', 'created_at' => now()]);
        PlatformAuditLog::create(['actor_id' => $this->admin->id, 'actor_type' => 'admin', 'action' => 'company.suspended', 'target_type' => 'company', 'target_id' => (string) $c->id, 'severity' => 'warning', 'created_at' => now()]);

        $items = DashboardWidgetRegistry::find('platform.recent_activity')->resolve([])['items'];

        $this->assertSame(['company.suspended'], array_column($items, 'action'));
        $this->assertSame('Journal Co', $items[0]['company_name']);
        $this->assertSame('Ad Min', $items[0]['actor']);
        $this->assertArrayNotHasKey('ip_address', $items[0]);
    }

    public function test_signups_widget_reads_the_measured_funnel(): void
    {
        $d = DashboardWidgetRegistry::find('platform.signups')->resolve([]);

        foreach (['started', 'completed', 'completed_previous', 'conversion', 'steps', 'daily'] as $k) {
            $this->assertArrayHasKey($k, $d);
        }
        $this->assertSame(0, $d['started']);
        $this->assertNull($d['conversion'], 'no journey → no invented rate');
        $this->assertCount(7, $d['daily'], 'the service trend covers the last 7 days (totals cover 30)');
    }

    public function test_companies_overview_only_gains_keys(): void
    {
        $d = DashboardWidgetRegistry::find('platform.companies_overview')->resolve([]);

        foreach (['active', 'suspended', 'total', 'new_30d', 'new_7d', 'trend', 'trend_30d', 'subscription_mix'] as $k) {
            $this->assertArrayHasKey($k, $d);
        }
        $this->assertCount(30, $d['trend_30d']);
    }

    public function test_size_bounds_were_only_relaxed(): void
    {
        // the previous bounds (before ADR-855) must still validate : no saved layout becomes invalid
        $before = [
            'platform.companies_overview' => [4, 12, 3, 4], 'platform.system_health' => [4, 12, 3, 4],
            'platform.commercial_overview' => [4, 12, 3, 5], 'platform.catalog_overview' => [3, 12, 2, 4], 'platform.plan_distribution' => [3, 12, 3, 5],
        ];
        foreach ($before as $key => [$minW, $maxW, $minH, $maxH]) {
            $l = DashboardWidgetRegistry::find($key)->layout();
            $this->assertLessThanOrEqual($minW, $l['min_w'], $key);
            $this->assertGreaterThanOrEqual($maxW, $l['max_w'], $key);
            $this->assertLessThanOrEqual($minH, $l['min_h'], $key);
            $this->assertGreaterThanOrEqual($maxH, $l['max_h'], $key);
        }
    }
}
