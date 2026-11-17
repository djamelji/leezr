<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Jobdomains\JobdomainRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Logistics Only — only the delivered jobdomain is offered to NEW companies (catalogue AND server),
 * while other jobdomains stay active for historical companies and Platform management.
 */
class LogisticsOnlySignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        JobdomainRegistry::sync();
        Jobdomain::create(['key' => 'beta-x', 'label' => 'Beta', 'is_active' => true, 'default_modules' => []]);
    }

    public function test_public_catalogue_offers_logistics_only(): void
    {
        $keys = collect($this->getJson('/api/public/plans')->assertOk()->json('jobdomains'))->pluck('key')->all();
        $this->assertSame(['logistique'], $keys);
    }

    public function test_registration_in_a_non_offered_jobdomain_is_refused_server_side(): void
    {
        $this->postJson('/api/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'x@example.test', 'password' => 'P@ssw0rd!Strong',
            'password_confirmation' => 'P@ssw0rd!Strong', 'company_name' => 'X', 'jobdomain_key' => 'beta-x', 'plan_key' => 'starter'])
            ->assertStatus(422)->assertJsonValidationErrors('jobdomain_key');
        $this->getJson('/api/public/addons?jobdomain=beta-x&plan=starter')->assertStatus(422);
        $this->assertTrue((bool) Jobdomain::where('key', 'beta-x')->value('is_active'), 'historical jobdomain stays active');
    }

    public function test_sync_never_overrides_the_platform_choice(): void
    {
        Jobdomain::where('key', 'logistique')->update(['signup_available' => false]);
        JobdomainRegistry::sync();
        $this->assertFalse((bool) Jobdomain::where('key', 'logistique')->value('signup_available'));
    }
}
