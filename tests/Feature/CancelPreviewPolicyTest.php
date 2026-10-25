<?php

namespace Tests\Feature;

use App\Core\Billing\PlatformBillingPolicy;
use App\Core\Billing\Subscription;
use App\Core\Markets\MarketRegistry;
use App\Core\Models\Company;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-631h (LOT 7E) — the subscription cancel-preview reads downgrade_timing from the SAME source as
 * the real cancellation (PlatformBillingPolicy). It previously referenced a non-existent class
 * (App\Core\Billing\BillingPolicy::get) and fatal-errored. This pins: no fatal, and the preview timing
 * matches the policy value.
 */
class CancelPreviewPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        MarketRegistry::sync();
    }

    private function admin(): PlatformUser
    {
        $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
        $role->permissions()->sync(PlatformPermission::whereIn('key', ['view_companies'])->pluck('id')->all());
        $u = PlatformUser::create(['first_name' => 'A', 'last_name' => 'U', 'email' => uniqid().'@t.com', 'password' => 'P@ssw0rd!Strong']);
        $u->roles()->attach($role->id);

        return $u;
    }

    private function companyWithSub(): Company
    {
        $c = Company::create(['name' => 'Prev Co', 'slug' => 'prev-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR', 'address_country_code' => 'FR']);
        Subscription::create([
            'company_id' => $c->id, 'plan_key' => 'pro', 'interval' => 'monthly', 'status' => 'active',
            'provider' => 'internal', 'is_current' => true, 'plan_price_cents' => 2900, 'plan_price_source' => 'subscribed',
            'current_period_start' => now()->subMonth(), 'current_period_end' => now()->addMonth(),
        ]);

        return $c;
    }

    public function test_cancel_preview_returns_timing_from_policy_without_fatal(): void
    {
        $policy = PlatformBillingPolicy::instance();
        $policy->update(['downgrade_timing' => 'end_of_period']);

        $c = $this->companyWithSub();

        $this->actingAs($this->admin(), 'platform')
            ->getJson("/api/platform/companies/{$c->id}/subscription/cancel-preview")
            ->assertOk()
            ->assertJsonPath('timing', 'end_of_period');
    }

    public function test_cancel_preview_reflects_immediate_policy(): void
    {
        PlatformBillingPolicy::instance()->update(['downgrade_timing' => 'immediate']);

        $c = $this->companyWithSub();

        $this->actingAs($this->admin(), 'platform')
            ->getJson("/api/platform/companies/{$c->id}/subscription/cancel-preview")
            ->assertOk()
            ->assertJsonPath('timing', 'immediate');
    }
}
