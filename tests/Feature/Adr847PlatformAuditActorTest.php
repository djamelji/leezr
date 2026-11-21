<?php

namespace Tests\Feature;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Audit\PlatformAuditLog;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-847 (Leezr 4.2 M8.2, R-M81-2) — the Platform audit log can no longer block a legitimate login because of a
 * length : actor_type is a fixed CATEGORY, the role key has its own column (as wide as platform_roles.key), the
 * client-supplied user agent is bounded AND flagged. Nothing is truncated silently, no identifier is cut.
 * NB : the test database (sqlite) does not enforce varchar lengths — the 500 itself was reproduced on the MySQL
 * clone (scripts/audit842-m82-fixture.php) ; here we pin the invariants that make it impossible.
 */
class Adr847PlatformAuditActorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
    }

    private function operator(string $roleKey, array $perms = ['view_companies']): PlatformUser
    {
        $u = PlatformUser::create(['first_name' => 'Op', 'last_name' => 'Long', 'email' => Str::lower(Str::random(8)).'@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $r = PlatformRole::create(['key' => $roleKey, 'name' => 'Role '.Str::random(4)]);
        $r->permissions()->sync(PlatformPermission::whereIn('key', $perms)->pluck('id')->all());
        $u->roles()->attach($r);

        return $u;
    }

    private function login(PlatformUser $u, array $headers = [])
    {
        $this->get('/sanctum/csrf-cookie');

        return $this->withHeaders($headers)->postJson('/api/platform/login', ['email' => $u->email, 'password' => 'P@ssw0rd!Strong']);
    }

    public function test_login_with_the_longest_allowed_role_key_is_audited_as_admin_with_its_full_role(): void
    {
        $key = str_repeat('r', 50); // RoleController validation : max 50
        $u = $this->operator($key);
        $this->login($u)->assertOk();
        $log = PlatformAuditLog::where('action', 'platform_auth.login')->where('actor_id', $u->id)->latest('id')->firstOrFail();
        $this->assertSame('admin', $log->actor_type);
        $this->assertSame($key, $log->actor_role, 'the role key is kept whole, never truncated');
    }

    public function test_super_admin_and_system_actors(): void
    {
        $super = PlatformUser::create(['first_name' => 'S', 'last_name' => 'A', 'email' => 'sa847@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $super->roles()->attach(PlatformRole::where('key', 'super_admin')->firstOrFail());
        $this->login($super)->assertOk();
        $log = PlatformAuditLog::where('actor_id', $super->id)->latest('id')->firstOrFail();
        $this->assertSame(['admin', 'super_admin'], [$log->actor_type, $log->actor_role]);

        $this->app['auth']->forgetGuards();
        $system = app(AuditLogger::class)->logPlatform('system.check', null, null, ['actorType' => 'system']);
        $this->assertSame(['system', null], [$system->actor_type, $system->actor_role]);
    }

    public function test_an_unknown_actor_category_fails_loudly_instead_of_being_truncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(AuditLogger::class)->logPlatform('system.check', null, null, ['actorType' => 'a_role_key_that_is_not_a_category']);
    }

    public function test_a_very_long_user_agent_never_blocks_the_login_and_is_flagged(): void
    {
        $u = $this->operator('ua_test');
        $this->login($u, ['User-Agent' => str_repeat('Mozilla/5.0 ', 300)])->assertOk();
        $log = PlatformAuditLog::where('actor_id', $u->id)->latest('id')->firstOrFail();
        $this->assertSame(AuditLogger::USER_AGENT_MAX, mb_strlen($log->user_agent));
        $this->assertTrue($log->metadata['user_agent_truncated']);
        $this->assertSame(3600, $log->metadata['user_agent_length']);
    }

    public function test_schema_and_validation_widths_are_coherent(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_12_18_000001_add_actor_role_to_platform_audit_logs.php'));
        $this->assertStringContainsString("string('actor_role', 255)", $migration, 'actor_role as wide as platform_roles.key');
        $roles = file_get_contents(app_path('Modules/Platform/Roles/Http/RoleController.php'));
        $this->assertMatchesRegularExpression('/max:(\d+)\|unique:platform_roles/', $roles);
        preg_match('/max:(\d+)\|unique:platform_roles/', $roles, $m);
        $this->assertLessThanOrEqual(255, (int) $m[1]);
        // every audit action fits its 100-character column
        foreach ((new \ReflectionClass(AuditAction::class))->getConstants() as $name => $value) {
            if (is_string($value)) {
                $this->assertLessThanOrEqual(100, strlen($value), "$name too long for audit_logs.action");
            }
        }
        $this->assertSame(['admin', 'system', 'webhook'], AuditLogger::PLATFORM_ACTOR_TYPES);
    }

    public function test_historical_rows_with_a_role_in_actor_type_still_read_as_admin_with_their_name(): void
    {
        $u = $this->operator('legacy_role', ['view_companies', 'view_activity_feed', 'view_audit_logs']);
        DB::table('platform_audit_logs')->insert(['id' => (string) Str::ulid(), 'actor_id' => $u->id, 'actor_type' => 'legacy_role', 'actor_role' => 'legacy_role', 'action' => 'platform_auth.login',
            'severity' => 'info', 'created_at' => now()]);
        $super = PlatformUser::create(['first_name' => 'S', 'last_name' => 'A', 'email' => 'sa847b@platform.test', 'password' => 'P@ssw0rd!Strong']);
        $super->roles()->attach(PlatformRole::where('key', 'super_admin')->firstOrFail());
        $item = collect($this->actingAs($super, 'platform')->getJson('/api/platform/activity')->assertOk()->json('data'))
            ->first(fn ($i) => $i['actor_id'] === $u->id && $i['source'] === 'platform');
        $this->assertSame('admin', $item['actor_type']);
        $this->assertSame('legacy_role', $item['actor_role']);
        $this->assertSame('Op Long', $item['actor_name']);
        $this->assertSame('legacy_role', DB::table('platform_audit_logs')->where('actor_id', $u->id)->value('actor_type'), 'stored history untouched');
    }

    public function test_suspended_company_is_flagged_for_the_spa_and_refusals_carry_a_code(): void
    {
        \App\Core\Modules\ModuleRegistry::sync();
        \App\Core\Markets\MarketRegistry::sync();
        \App\Core\Plans\PlanRegistry::sync();
        $company = \App\Core\Models\Company::create(['name' => 'Suspendue', 'slug' => 'suspendue-847', 'plan_key' => 'starter', 'jobdomain_key' => 'logistique', 'market_key' => 'FR']);
        $owner = \App\Core\Models\User::factory()->create();
        $company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAs($owner, 'web');
        $this->getJson('/api/my-companies')->assertOk()->assertJsonPath('companies.0.suspended', false);

        DB::table('companies')->where('id', $company->id)->update(['status' => 'suspended']);
        $this->getJson('/api/my-companies')->assertOk()->assertJsonPath('companies.0.suspended', true);
        $this->withHeaders(['X-Company-Id' => $company->id])->getJson('/api/nav')
            ->assertForbidden()->assertJsonPath('code', 'company_suspended'); // the server stays the authority
    }
}
