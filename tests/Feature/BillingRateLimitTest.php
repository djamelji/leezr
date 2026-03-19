<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Plans\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

class BillingRateLimitTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PlanRegistry::sync();

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Rate Limit Co',
            'slug' => 'rate-limit-co',
            'plan_key' => 'pro',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($this->company);
    }

    public function test_billing_routes_are_rate_limited(): void
    {
        $headers = ['X-Company-Id' => $this->company->id];

        for ($i = 0; $i < 60; $i++) {
            $response = $this->actingAs($this->owner)
                ->getJson('/api/billing/overview', $headers);

            $this->assertNotEquals(429, $response->status(), "Got 429 on request #{$i}");
        }

        // 61st request should be rate limited
        $response = $this->actingAs($this->owner)
            ->getJson('/api/billing/overview', $headers);

        $this->assertEquals(429, $response->status());
    }

    public function test_billing_rate_limit_is_per_user(): void
    {
        $user2 = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $user2->id,
            'role' => 'admin',
        ]);

        $headers = ['X-Company-Id' => $this->company->id];

        // User 1 exhausts their limit
        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($this->owner)
                ->getJson('/api/billing/overview', $headers);
        }

        // User 1 gets 429
        $response = $this->actingAs($this->owner)
            ->getJson('/api/billing/overview', $headers);

        $this->assertEquals(429, $response->status());

        // User 2 should still be able to access
        $response = $this->actingAs($user2)
            ->getJson('/api/billing/overview', $headers);

        $this->assertNotEquals(429, $response->status());
    }
}
