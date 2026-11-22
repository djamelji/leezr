<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-861 (Leezr 5.0 L7.11) — the intermittent « refused calls » of the browser recipe were 429 on the two bootstrap
 * endpoints every full page load reads (30/min per IP) : an office behind one address hit the same limit and silently
 * fell back to default locale, currency and theme. Both now have a bootstrap budget ; the SPA asks the world once per load.
 */
class Adr861PublicBootstrapBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    public function test_an_office_behind_one_address_never_hits_the_bootstrap_limit(): void
    {
        // 40 people opening Leezr in the same minute from one office IP, two bootstrap calls each
        foreach (range(1, 40) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/api/public/world')->assertOk();
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/api/public/theme')->assertOk();
        }
    }

    public function test_the_budget_still_exists(): void
    {
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === 'api/public/world');
        $this->assertContains('throttle:600,1', $route->gatherMiddleware());
    }
}
