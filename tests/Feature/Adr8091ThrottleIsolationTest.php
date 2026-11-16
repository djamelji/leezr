<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * ADR-809.1 — public auth operations keep their own rate-limit counter. Laravel's numeric
 * `throttle:N,M` keys on `prefix + sha1(domain|ip)` only : without a prefix every public route of an
 * IP shared ONE counter, so preparatory signup calls (plans, fields, estimate, funnel, theme…) consumed
 * the account-creation quota and /register answered 429. Limits are unchanged ; only counters split.
 */
class Adr8091ThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withMiddleware(ThrottleRequests::class);
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function hit(string $method, string $uri, int $times, array $body = [], array $headers = []): array
    {
        $statuses = [];
        for ($i = 0; $i < $times; $i++) {
            $statuses[] = $this->json($method, $uri, $body, $headers)->status();
        }

        return $statuses;
    }

    public function test_preparatory_signup_calls_do_not_consume_the_registration_quota(): void
    {
        // A realistic signup session : theme/world, plans, fields, addons, estimates, funnel steps.
        foreach (['/api/public/theme', '/api/public/world', '/api/public/plans', '/api/public/fields', '/api/public/addons'] as $uri) {
            $this->hit('GET', $uri, 3);
        }
        $this->hit('POST', '/api/register/funnel-track', 12, ['step' => 'company']);

        // Registration still has its full quota : 10 attempts processed (422 = validation), the 11th throttled.
        $statuses = $this->hit('POST', '/api/register', 11, []);
        $this->assertNotContains(429, array_slice($statuses, 0, 10), json_encode($statuses));
        $this->assertSame(429, $statuses[10]);
    }

    public function test_each_operation_is_throttled_at_its_unchanged_limit_and_isolated(): void
    {
        $ops = [
            ['/api/login', 15, ['email' => 'nobody@example.test', 'password' => 'x']],
            ['/api/2fa/verify', 5, ['code' => '000000']],
            ['/api/forgot-password', 5, ['email' => 'nobody@example.test']],
            ['/api/reset-password', 5, ['email' => 'nobody@example.test', 'token' => 'x', 'password' => 'Passw0rd!x', 'password_confirmation' => 'Passw0rd!x']],
            ['/api/platform/login', 15, ['email' => 'nobody@example.test', 'password' => 'x']],
            ['/api/platform/2fa/verify', 5, ['code' => '000000']],
            ['/api/platform/forgot-password', 5, ['email' => 'nobody@example.test']],
            ['/api/platform/reset-password', 5, ['email' => 'nobody@example.test', 'token' => 'x', 'password' => 'Passw0rd!x', 'password_confirmation' => 'Passw0rd!x']],
            ['/api/public/validate-coupon', 10, ['code' => 'NOPE']],
        ];

        // Exhausting one operation after another : each still has its own full quota, then 429.
        foreach ($ops as [$uri, $limit, $body]) {
            $statuses = $this->hit('POST', $uri, $limit + 1, $body);
            $this->assertNotContains(429, array_slice($statuses, 0, $limit), "$uri ".json_encode($statuses));
            $this->assertSame(429, $statuses[$limit], $uri);
        }

        // Every operation stays throttled (no counter was reset by another one).
        foreach ($ops as [$uri, , $body]) {
            $this->assertSame(429, $this->json('POST', $uri, $body)->status(), "$uri still throttled");
        }
    }

    public function test_throttle_cannot_be_bypassed_by_identity_or_forwarded_headers(): void
    {
        $this->hit('POST', '/api/login', 15, ['email' => 'a@example.test', 'password' => 'x']);

        // Another e-mail, a spoofed client IP : same client, same counter.
        $this->assertSame(429, $this->postJson('/api/login', ['email' => 'b@example.test', 'password' => 'x'])->status());
        $this->assertSame(429, $this->postJson('/api/login', ['email' => 'c@example.test', 'password' => 'x'], ['X-Forwarded-For' => '203.0.113.9', 'X-Real-IP' => '203.0.113.9'])->status());

        // A genuinely different client is not affected.
        $this->assertNotSame(429, $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/api/login', ['email' => 'a@example.test', 'password' => 'x'])->status());
    }
}
