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

    // ADR-862 — the 600/min budget is safe : both endpoints are anonymous GETs, write nothing, and the theme is cached
    public function test_the_bootstrap_endpoints_are_anonymous_read_only_and_the_theme_is_served_from_cache(): void
    {
        foreach (['api/public/world', 'api/public/theme'] as $uri) {
            $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $uri);
            $this->assertEmpty(array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, 'auth')), $uri);
        }
        \Illuminate\Support\Facades\Cache::flush();
        $this->getJson('/api/public/theme')->assertOk(); // fills the cache

        \Illuminate\Support\Facades\DB::enableQueryLog();
        foreach (['world', 'theme'] as $e) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->getJson("/api/public/$e")->assertOk();
        }
        $queries = array_column(\Illuminate\Support\Facades\DB::getQueryLog(), 'query');
        $this->assertEmpty(array_filter($queries, fn ($q) => ! preg_match('/^\s*select/i', $q)), 'no write');
        $this->assertEmpty(array_filter($queries, fn ($q) => str_contains($q, 'platform_settings')), 'theme from cache');
        $this->assertLessThanOrEqual(2, count($queries), 'world = the default market (and its fallback), nothing else');
    }

    public function test_a_theme_change_is_served_at_once(): void
    {
        $before = $this->getJson('/api/public/theme')->assertOk()->json('primary_color');
        $settings = \App\Platform\Models\PlatformSetting::instance();
        $settings->update(['theme' => array_merge((array) $settings->theme, ['primary_color' => '#123456'])]);
        $after = $this->getJson('/api/public/theme')->assertOk()->json('primary_color');
        $this->assertNotSame($before, $after);
        $this->assertSame('#123456', $after);
    }

    // ADR-862 — the bootstrap budget does not leak into the other public limits (separate counters per limit)
    public function test_bootstrap_calls_do_not_consume_the_budget_of_other_public_endpoints(): void
    {
        foreach (range(1, 35) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->getJson('/api/public/theme')->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->getJson('/api/public/plans')->assertOk(); // limit 30, untouched
    }
}
