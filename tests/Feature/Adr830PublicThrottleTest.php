<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-830 — each public read endpoint family has its OWN rate-limit bucket. Unnamed `throttle:N,1`
 * middlewares share one counter per user/IP : the periodic version check (every tab) used to exhaust the
 * budget of /public/plans and the billing pages failed with 429. Limits themselves are unchanged.
 */
class Adr830PublicThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_version_check_does_not_consume_the_plans_budget(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/public/version')->assertOk();
        }
        $this->getJson('/api/public/plans')->assertOk();
        $this->getJson('/api/public/theme')->assertOk();
        $this->getJson('/api/public/world')->assertOk();
    }

    public function test_each_family_keeps_its_own_limit(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        // ADR-861 : theme / world are the bootstrap of every page load (600 / min, Adr861PublicBootstrapBudgetTest) ;
        // the public plans family keeps 30 / min, and exhausting it never blocks the bootstrap
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/public/plans')->assertOk();
        }
        $this->getJson('/api/public/plans')->assertStatus(429); // unchanged limit : 30 / min
        $this->getJson('/api/public/theme')->assertOk();
    }
}
