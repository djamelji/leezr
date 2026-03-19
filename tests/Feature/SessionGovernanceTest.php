<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\SessionSettingsPayload;
use App\Http\Middleware\SessionGovernance;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();

        $this->user = User::factory()->create();
        $this->platformAdmin = PlatformUser::create([
            'name' => 'Session Admin',
            'email' => 'session-admin@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    /**
     * Generate a valid 40-char alphanumeric session ID.
     */
    private function validSessionId(string $seed = 'test'): string
    {
        return str_pad(md5($seed), 40, 'a');
    }

    /**
     * Create a request with a session bound to a specific session ID.
     */
    private function makeRequestWithSession(string $sessionId, string $uri = '/api/test', string $method = 'GET'): Request
    {
        $request = Request::create($uri, $method);
        $store = new Store('leezr_session', new ArraySessionHandler(120), $sessionId);
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }

    /**
     * Insert a session row in the database with a given last_activity.
     */
    private function insertSessionRow(string $sessionId, int $lastActivity): void
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => $lastActivity,
        ]);
    }

    // ── Middleware Guard Tests ──────────────────────────────

    public function test_middleware_skips_when_driver_not_database(): void
    {
        config(['session.driver' => 'array']);

        $middleware = new SessionGovernance;
        $request = Request::create('/api/test', 'GET');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->status());
        $this->assertNull($response->headers->get('X-Session-TTL'));
    }

    public function test_middleware_skips_when_no_session(): void
    {
        config(['session.driver' => 'database']);

        $middleware = new SessionGovernance;
        $request = Request::create('/api/test', 'GET');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->status());
    }

    public function test_middleware_skips_logout_path(): void
    {
        config(['session.driver' => 'database']);

        $sessionId = $this->validSessionId('logout');
        $this->insertSessionRow($sessionId, time() - 86400); // Expired

        $request = $this->makeRequestWithSession($sessionId, '/api/logout', 'POST');

        $middleware = new SessionGovernance;
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        // Even with expired session, logout path should pass through
        $this->assertEquals(200, $response->status());
    }

    public function test_middleware_skips_when_session_row_missing(): void
    {
        config(['session.driver' => 'database']);

        $request = $this->makeRequestWithSession($this->validSessionId('nonexistent'));

        $middleware = new SessionGovernance;
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->status());
        $this->assertNull($response->headers->get('X-Session-TTL'));
    }

    // ── Expiration Detection ───────────────────────────────

    public function test_middleware_returns_401_when_session_expired(): void
    {
        config(['session.driver' => 'database']);

        $sessionId = $this->validSessionId('expired');
        $this->insertSessionRow($sessionId, time() - 86400); // 24h ago

        $request = $this->makeRequestWithSession($sessionId, '/api/heartbeat', 'POST');

        $middleware = new SessionGovernance;
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->status());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Session expired due to inactivity.', $data['message']);
    }

    public function test_middleware_returns_401_when_exactly_at_timeout(): void
    {
        config(['session.driver' => 'database']);

        $idle = SessionSettingsPayload::fromSettings()->idleTimeout * 60;
        $sessionId = $this->validSessionId('boundary');
        $this->insertSessionRow($sessionId, time() - $idle); // Exactly at boundary

        $request = $this->makeRequestWithSession($sessionId);

        $middleware = new SessionGovernance;
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->status());
    }

    // ── TTL Header ─────────────────────────────────────────

    public function test_middleware_sets_ttl_header_on_active_session(): void
    {
        config(['session.driver' => 'database']);

        $sessionId = $this->validSessionId('active');
        $this->insertSessionRow($sessionId, time()); // Just now

        $request = $this->makeRequestWithSession($sessionId);

        $middleware = new SessionGovernance;
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->status());
        $this->assertNotNull($response->headers->get('X-Session-TTL'));

        $ttl = (int) $response->headers->get('X-Session-TTL');
        $this->assertEquals(7200, $ttl); // 120 min * 60
    }

    public function test_middleware_uses_custom_idle_timeout(): void
    {
        config(['session.driver' => 'database']);

        // Set custom idle_timeout
        $settings = DB::table('platform_settings')->first();

        if ($settings) {
            $session = json_decode($settings->session ?? '{}', true);
            $session['idle_timeout'] = 30; // 30 minutes
            DB::table('platform_settings')
                ->where('id', $settings->id)
                ->update(['session' => json_encode($session)]);
        }

        $sessionId = $this->validSessionId('custom');
        $this->insertSessionRow($sessionId, time());

        $request = $this->makeRequestWithSession($sessionId);

        $middleware = new SessionGovernance;
        $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->status());
        $ttl = (int) $response->headers->get('X-Session-TTL');
        $this->assertEquals(1800, $ttl); // 30 min * 60
    }

    // ── Heartbeat Integration Tests ────────────────────────

    public function test_heartbeat_endpoint_returns_204(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/heartbeat');

        $response->assertNoContent();
    }

    public function test_platform_heartbeat_endpoint_returns_204(): void
    {
        $response = $this->actingAs($this->platformAdmin, 'platform')
            ->postJson('/api/platform/heartbeat');

        $response->assertNoContent();
    }

    public function test_unauthenticated_heartbeat_rejected(): void
    {
        $response = $this->postJson('/api/heartbeat');

        $this->assertContains($response->status(), [401, 302]);
    }

    // ── Settings Payload ───────────────────────────────────

    public function test_session_settings_payload_defaults(): void
    {
        $payload = SessionSettingsPayload::fromSettings();

        $this->assertEquals(120, $payload->idleTimeout);
        $this->assertEquals(5, $payload->warningThreshold);
        $this->assertEquals(10, $payload->heartbeatInterval);
        $this->assertFalse($payload->rememberMeEnabled);
        $this->assertEquals(43200, $payload->rememberMeDuration);
    }

    public function test_session_settings_payload_frontend_array(): void
    {
        $payload = SessionSettingsPayload::fromSettings();
        $frontend = $payload->toFrontendArray();

        $this->assertArrayHasKey('idle_timeout', $frontend);
        $this->assertArrayHasKey('warning_threshold', $frontend);
        $this->assertArrayHasKey('heartbeat_interval', $frontend);
        // Should NOT expose remember_me fields to frontend
        $this->assertEquals(3, count($frontend));
    }
}
