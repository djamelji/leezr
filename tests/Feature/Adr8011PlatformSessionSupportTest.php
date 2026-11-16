<?php

namespace Tests\Feature;

use App\Core\Auth\ScopedDatabaseSessionHandler;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformPermission;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-801.1 — Platform sessions (database driver, real cookie replayed between requests) and support
 * assignment without administration.
 */
class Adr8011PlatformSessionSupportTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'P@ssw0rd!Strong';

    private ?string $cookie = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        config(['session.driver' => 'database']);
        ScopedDatabaseSessionHandler::flushColumnCache();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** One HTTP request as a browser would send it : same session cookie, fresh guards and store. */
    private function browser(string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        config(['auth.defaults.guard' => 'web']); // shouldUse() of the previous request must not leak (fresh process in real life)
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store'); // guards and StartSession must share ONE store, as in a real request
        $name = config('session.cookie');
        $req = $this->withHeader('Origin', 'https://leezr.test')->withCredentials(); // JSON calls send cookies only with credentials
        if ($this->cookie) {
            $req = $req->withCookie($name, $this->cookie);
        }
        $res = $req->json($method, $uri, $data);
        if ($c = $res->getCookie($name)) {
            $this->cookie = $c->getValue();
        }

        return $res;
    }

    private function sessionRow(): ?object
    {
        return DB::table('sessions')->where('id', $this->cookie)->first();
    }

    private function platformUser(array $permissions = [], ?int $id = null): PlatformUser
    {
        $u = new PlatformUser(['first_name' => 'P', 'last_name' => 'U', 'email' => uniqid().'@p.test', 'password' => self::PASSWORD]);
        if ($id) {
            $u->id = $id;
        }
        $u->save();
        if ($permissions) {
            $role = PlatformRole::create(['key' => 'r_'.uniqid(), 'name' => 'R']);
            $role->permissions()->sync(PlatformPermission::whereIn('key', $permissions)->pluck('id')->all());
            $u->roles()->attach($role->id);
        }

        return $u;
    }

    private function companyUser(?int $id = null): User
    {
        return User::factory()->create(array_filter(['id' => $id, 'password' => self::PASSWORD]));
    }

    private function loginPlatform(PlatformUser $u)
    {
        return $this->browser('POST', '/api/platform/login', ['email' => $u->email, 'password' => self::PASSWORD]);
    }

    private function loginCompany(User $u)
    {
        return $this->browser('POST', '/api/login', ['email' => $u->email, 'password' => self::PASSWORD]);
    }

    // ── 1. Platform session ─────────────────────────────────────────────

    public function test_platform_login_works_when_the_id_does_not_exist_in_users(): void
    {
        $p = $this->platformUser([], 9001);
        $this->assertFalse(User::whereKey(9001)->exists());

        $this->loginPlatform($p)->assertOk();
        $this->browser('GET', '/api/platform/me')->assertOk()->assertJsonPath('user.id', 9001);

        $row = $this->sessionRow();
        $this->assertSame(9001, (int) $row->platform_user_id);
        $this->assertNull($row->user_id, 'a Platform identity is never written into the Company column');
    }

    public function test_platform_login_never_links_the_session_to_a_company_user_with_the_same_id(): void
    {
        $company = $this->companyUser(4242);
        $p = $this->platformUser([], 4242);

        $this->loginPlatform($p)->assertOk();
        $this->browser('GET', '/api/platform/me')->assertOk();
        $this->assertNull($this->sessionRow()->user_id);
        $this->assertSame(4242, (int) $this->sessionRow()->platform_user_id);

        // The same-numbered Company user is NOT authenticated by this session.
        $this->browser('GET', '/api/me')->assertUnauthorized();
        $this->assertNotNull($company->fresh());
    }

    public function test_company_session_is_unchanged(): void
    {
        $c = $this->companyUser();
        $this->loginCompany($c)->assertOk();
        $this->browser('GET', '/api/me')->assertOk()->assertJsonPath('user.id', $c->id);
        $this->assertSame($c->id, (int) $this->sessionRow()->user_id);
        $this->assertNull($this->sessionRow()->platform_user_id);
        $this->browser('GET', '/api/platform/me')->assertUnauthorized(); // no scope leak
    }

    public function test_simultaneous_scopes_are_isolated_and_logout_is_scoped(): void
    {
        $c = $this->companyUser(77);
        $p = $this->platformUser([], 78);

        $this->loginCompany($c)->assertOk();
        $this->loginPlatform($p)->assertOk();
        $this->browser('GET', '/api/me')->assertOk()->assertJsonPath('user.id', 77);
        $this->browser('GET', '/api/platform/me')->assertOk()->assertJsonPath('user.id', 78);
        $row = $this->sessionRow();
        $this->assertSame([77, 78], [(int) $row->user_id, (int) $row->platform_user_id]);

        // Platform logout : the Company login survives, the session id is rotated (old id destroyed).
        $before = $this->cookie;
        $this->browser('POST', '/api/platform/logout')->assertOk();
        $this->assertNotSame($before, $this->cookie);
        $this->assertFalse(DB::table('sessions')->where('id', $before)->exists());
        $this->browser('GET', '/api/platform/me')->assertUnauthorized();
        $this->browser('GET', '/api/me')->assertOk()->assertJsonPath('user.id', 77);
        $this->assertNull($this->sessionRow()->platform_user_id);

        // Company logout with no other scope : full invalidation, as before.
        $this->browser('POST', '/api/logout')->assertOk();
        $this->browser('GET', '/api/me')->assertUnauthorized();
        $this->browser('GET', '/api/platform/me')->assertUnauthorized();
    }

    public function test_company_logout_keeps_the_platform_login(): void
    {
        $this->loginCompany($this->companyUser())->assertOk();
        $this->loginPlatform($p = $this->platformUser())->assertOk();
        $this->browser('POST', '/api/logout')->assertOk();
        $this->browser('GET', '/api/me')->assertUnauthorized();
        $this->browser('GET', '/api/platform/me')->assertOk()->assertJsonPath('user.id', $p->id);
    }

    public function test_session_fixation_login_rotates_the_session_id(): void
    {
        $this->browser('GET', '/api/platform/me')->assertUnauthorized(); // anonymous session issued
        $anonymous = $this->cookie;
        $this->assertNotNull($anonymous);
        $this->loginPlatform($this->platformUser())->assertOk();
        $this->assertNotSame($anonymous, $this->cookie, 'login must regenerate the session id');
        $this->assertFalse(DB::table('sessions')->where('id', $anonymous)->whereNotNull('platform_user_id')->exists());
    }

    public function test_idle_expiration_logs_out(): void
    {
        $this->loginPlatform($this->platformUser())->assertOk();
        $this->browser('GET', '/api/platform/me')->assertOk();
        DB::table('sessions')->where('id', $this->cookie)->update(['last_activity' => time() - 86400 * 30]);
        $this->browser('GET', '/api/platform/me')->assertUnauthorized();
    }

    public function test_deleting_a_platform_user_removes_its_sessions_only(): void
    {
        $c = $this->companyUser();
        $this->loginCompany($c)->assertOk();
        $this->loginPlatform($p = $this->platformUser())->assertOk();
        $this->browser('GET', '/api/platform/me')->assertOk();
        $id = $this->cookie;

        $p->roles()->detach();
        $p->delete(); // FK sessions.platform_user_id → platform_users ON DELETE CASCADE
        $this->assertFalse(DB::table('sessions')->where('id', $id)->exists());
        $this->assertNotNull($c->fresh());
    }

    // ── 3. Support : assign without administering ───────────────────────

    private function ticket(?int $assignee = null): SupportTicket
    {
        $company = Company::create(['name' => 'Sup Co', 'slug' => 'sup-'.uniqid(), 'plan_key' => 'starter', 'jobdomain_key' => 'logistique']);

        return SupportTicket::create(['company_id' => $company->id, 'created_by_user_id' => User::factory()->create()->id, 'subject' => 'Help', 'assigned_to_platform_user_id' => $assignee]);
    }

    public function test_assigner_reads_only_the_minimal_queue_and_assigns_to_an_eligible_agent(): void
    {
        $agent = $this->platformUser(['manage_support']);
        $assigner = $this->platformUser(['assign_support']);
        $ticket = $this->ticket();
        $this->ticket()->update(['status' => 'closed']); // closed tickets are not in the default queue

        $queue = $this->actingAs($assigner, 'platform')->getJson('/api/platform/support/assignment-queue')->assertOk();
        $this->assertSame(1, $queue->json('total'));
        $row = $queue->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'company_id', 'assigned_to_platform_user_id', 'subject', 'status', 'priority', 'category', 'created_at', 'last_message_at', 'company', 'assignee'], array_keys($row));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($row['company']));

        $agents = $this->actingAs($assigner, 'platform')->getJson('/api/platform/support/assignees')->assertOk()->json('assignees');
        $ids = array_column($agents, 'id');
        $this->assertContains($agent->id, $ids);
        $this->assertNotContains($assigner->id, $ids, 'assign_support alone cannot take charge');
        $this->assertSame(['id', 'first_name', 'last_name'], array_keys($agents[0]), 'no e-mail or other personal data');

        $this->actingAs($assigner, 'platform')->putJson("/api/platform/support/tickets/{$ticket->id}/assign", ['platform_user_id' => $agent->id])->assertOk();
        $this->assertSame($agent->id, $ticket->fresh()->assigned_to_platform_user_id);
    }

    public function test_assignment_to_a_non_eligible_user_is_refused(): void
    {
        $assigner = $this->platformUser(['assign_support']);
        $finance = $this->platformUser(['view_billing']);
        $ticket = $this->ticket();

        foreach ([$finance->id, null /* self : assign_support alone cannot take charge */] as $target) {
            $this->actingAs($assigner, 'platform')->putJson("/api/platform/support/tickets/{$ticket->id}/assign", ['platform_user_id' => $target])
                ->assertStatus(422)->assertJsonPath('code', 'assignee_not_eligible');
        }
        $this->assertNull($ticket->fresh()->assigned_to_platform_user_id);
    }

    public function test_assigner_gets_nothing_administrative(): void
    {
        $assigner = $this->platformUser(['assign_support']);
        $ticket = $this->ticket();
        foreach ([
            ['GET', '/api/platform/support/tickets'], ['GET', '/api/platform/support/metrics'],
            ['GET', "/api/platform/support/tickets/{$ticket->id}"], ['GET', "/api/platform/support/tickets/{$ticket->id}/messages"],
            ['POST', "/api/platform/support/tickets/{$ticket->id}/internal-notes"], ['PUT', "/api/platform/support/tickets/{$ticket->id}/resolve"],
            ['PUT', "/api/platform/support/tickets/{$ticket->id}/close"], ['PUT', "/api/platform/support/tickets/{$ticket->id}/priority"],
            ['GET', '/api/platform/documentation/articles'],
        ] as [$m, $uri]) {
            $this->actingAs($assigner, 'platform')->json($m, $uri, ['body' => 'x', 'priority' => 'high'])->assertForbidden();
        }
    }

    public function test_manage_support_keeps_everything_and_history_is_untouched(): void
    {
        $agent = $this->platformUser(['manage_support']);
        $legacyAssignee = $this->platformUser(['view_billing']); // assigned before ADR-801.1 rules
        $old = $this->ticket($legacyAssignee->id);
        $new = $this->ticket();

        $this->actingAs($agent, 'platform')->getJson('/api/platform/support/tickets')->assertOk();
        $this->actingAs($agent, 'platform')->getJson('/api/platform/support/assignment-queue')->assertOk();
        $this->actingAs($agent, 'platform')->putJson("/api/platform/support/tickets/{$new->id}/assign")->assertOk(); // self
        $this->actingAs($agent, 'platform')->putJson("/api/platform/support/tickets/{$new->id}/resolve")->assertOk();
        $this->assertSame($legacyAssignee->id, $old->fresh()->assigned_to_platform_user_id, 'historical assignment preserved');
    }

    public function test_queue_and_assignees_refused_without_support_permission(): void
    {
        $u = $this->platformUser(['view_dashboard']);
        $this->actingAs($u, 'platform')->getJson('/api/platform/support/assignment-queue')->assertForbidden();
        $this->actingAs($u, 'platform')->getJson('/api/platform/support/assignees')->assertForbidden();
    }
}
