<?php

namespace Tests\Feature;

use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Realtime\ConnectionTracker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ADR-827 — SSE capacity : every open stream holds one PHP worker. A refused stream releases what it took,
 * the optional cap on open streams bounds the workers held (one user with several tabs included), and a
 * refusal is a real 429 (it used to be a 500 : JsonResponse returned where StreamedResponse was declared).
 * Runs on an isolated Redis key prefix ; skipped when Redis is not reachable.
 */
class Adr827RealtimeCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const C = 999201;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.options.prefix' => 'adr827-test-'.getmypid().'-']);
        Redis::purge(config('realtime.redis_connection', 'default'));
        try {
            $this->conn()->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis not reachable');
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->conn()->keys('*') as $k) {
                $this->conn()->del(substr($k, strlen(config('database.redis.options.prefix'))));
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function conn()
    {
        return Redis::connection(config('realtime.redis_connection', 'default'));
    }

    private function counts(): array
    {
        return ['streams' => ConnectionTracker::streamsTotal(), 'global' => ConnectionTracker::globalCount(),
            'company' => (int) $this->conn()->get('leezr:realtime:stream:count:'.self::C)];
    }

    public function test_the_stream_cap_bounds_open_streams_even_for_one_user_with_several_tabs(): void
    {
        config(['realtime.max_concurrent_streams' => 2]);

        $this->assertTrue(ConnectionTracker::connect(1, self::C));
        $this->assertTrue(ConnectionTracker::connect(1, self::C), 'second tab of the same user');
        $this->assertFalse(ConnectionTracker::connect(1, self::C), 'third tab : the cap is on streams, not users');
        $this->assertFalse(ConnectionTracker::connect(2, self::C), 'another user : cap reached');
        $this->assertSame(['streams' => 2, 'global' => 1, 'company' => 1], $this->counts());

        ConnectionTracker::disconnect(1, self::C);
        $this->assertSame(['streams' => 1, 'global' => 1, 'company' => 1], $this->counts());
        ConnectionTracker::disconnect(1, self::C);
        $this->assertSame(['streams' => 0, 'global' => 0, 'company' => 0], $this->counts(), 'nothing leaks');
    }

    public function test_a_refused_stream_releases_its_reference(): void
    {
        config(['realtime.max_streams_global' => 0]);

        $this->assertFalse(ConnectionTracker::connect(3, self::C));
        $this->assertSame(['streams' => 0, 'global' => 0, 'company' => 0], $this->counts());
        $this->assertNull($this->conn()->get('leezr:realtime:stream:streams:3:'.self::C), 'no reference left for the user');

        config(['realtime.max_streams_global' => 500]);
        $this->assertTrue(ConnectionTracker::connect(3, self::C), 'the user is not wrongly seen as already connected');
        $this->assertSame(['streams' => 1, 'global' => 1, 'company' => 1], $this->counts());
        ConnectionTracker::disconnect(3, self::C);
    }

    public function test_a_refused_stream_is_a_real_429(): void
    {
        Model::unguard();
        Jobdomain::firstOrCreate(['key' => 'logistique'], ['label' => 'L', 'default_modules' => []]);
        Market::firstOrCreate(['key' => 'FR'], ['name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $company = Company::create(['name' => 'Cap', 'slug' => 'cap-'.uniqid(), 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $user = User::factory()->create();
        Membership::create(['user_id' => $user->id, 'company_id' => $company->id, 'role' => 'owner']);
        Model::reguard();
        config(['realtime.max_concurrent_streams' => 1]);
        $this->conn()->set('leezr:realtime:stream:count:streams', 1);

        $this->actingAs($user)->get('/api/realtime/stream?company_id='.$company->id)
            ->assertStatus(429)->assertJson(['message' => 'Too many connections.']);
        $this->assertSame(1, ConnectionTracker::streamsTotal(), 'the refused stream took nothing');
    }
}
