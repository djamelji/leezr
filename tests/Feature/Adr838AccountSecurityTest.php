<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyRole;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Audit\PlatformAuditLog;
use App\Core\Auth\TwoFactorCredential;
use App\Core\Auth\TwoFactorService;
use App\Core\Fields\FieldDefinitionCatalog;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Notifications\Security\AccountSecurityNotification;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * ADR-838 (Leezr 4.2 M2) — sensitive account operations : 2FA deactivation and backup-code regeneration need a
 * fresh factor (throttled, single use, atomic, audited without secrets) ; admin password changes end the target's
 * sessions, notify, audit ; scopes and tenants stay isolated.
 */
class Adr838AccountSecurityTest extends TestCase
{
    use ActivatesCompanyModules, RefreshDatabase, SetsUpCompanyRbac;

    private const STRONG = 'Xk9#mW4!qZ7pL2v';

    private Company $company;

    private User $admin;

    private User $member;

    private $memberMembership;

    private Google2FA $g2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        FieldDefinitionCatalog::sync();
        config(['session.driver' => 'database']);
        $this->g2fa = new Google2FA();

        $this->admin = User::factory()->create(['password' => bcrypt('password')]);
        $this->member = User::factory()->create(['password' => bcrypt('password')]);
        $owner = User::factory()->create();
        $this->company = Company::create(['name' => 'Secure Co', 'slug' => 'secure-co', 'jobdomain_key' => 'logistique']);
        $this->activateCompanyModules($this->company);
        $adminRole = $this->setUpCompanyRbac($this->company);
        $this->company->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->company->memberships()->create(['user_id' => $this->admin->id, 'role' => 'user', 'company_role_id' => $adminRole->id]);
        $viewer = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'viewer', 'name' => 'Viewer', 'is_administrative' => false]);
        $viewer->permissions()->sync(CompanyPermission::whereIn('key', ['members.view'])->pluck('id')->all());
        $this->memberMembership = $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => 'user', 'company_role_id' => $viewer->id]);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function enable2fa(User|PlatformUser $user): TwoFactorCredential
    {
        $service = app(TwoFactorService::class);
        $service->enable($user);
        $credential = TwoFactorCredential::where('authenticatable_type', get_class($user))->where('authenticatable_id', $user->id)->first();
        $service->confirm($user, $this->g2fa->getCurrentOtp($credential->secret));

        return $credential->fresh();
    }

    private function otp(TwoFactorCredential $credential): string
    {
        return $this->g2fa->getCurrentOtp($credential->secret);
    }

    private function fakeSession(string $id, ?int $userId = null, ?int $platformUserId = null): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'platform_user_id' => $platformUserId,
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => base64_encode('x'), 'last_activity' => time()]);
    }

    private function assertNoSecretInAudit(array $secrets): void
    {
        $dump = json_encode([CompanyAuditLog::all()->toArray(), PlatformAuditLog::all()->toArray()]);
        foreach (array_filter($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, $dump, 'no secret in the audit log');
        }
    }

    // ── 1–3 : deactivation ────────────────────────────────────────────────────────────────────────────────

    public function test_1_disable_with_password_and_current_totp_is_audited_and_notified(): void
    {
        Notification::fake();
        $credential = $this->enable2fa($this->admin);
        $code = $this->otp($credential);

        $this->as($this->admin)->deleteJson('/api/2fa', ['password' => 'password', 'code' => $code])->assertOk();

        $this->assertDatabaseMissing('two_factor_credentials', ['authenticatable_id' => $this->admin->id, 'authenticatable_type' => User::class]);
        $log = CompanyAuditLog::where('action', 'two_factor.disabled')->sole();
        $this->assertSame((string) $this->admin->id, $log->target_id);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['result' => 'success', 'factor' => 'totp'], $log->metadata);
        Notification::assertSentTo($this->admin, AccountSecurityNotification::class, fn ($n) => $n->event === 'two_factor_disabled');
        $this->assertNoSecretInAudit([$code, 'password', $credential->secret]);
    }

    public function test_2_disable_is_refused_without_a_factor_even_with_the_right_password(): void
    {
        $this->enable2fa($this->admin);
        $this->as($this->admin)->deleteJson('/api/2fa', ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseHas('two_factor_credentials', ['authenticatable_id' => $this->admin->id, 'enabled' => true]);
    }

    public function test_3_disable_is_refused_with_an_invalid_expired_or_replayed_code(): void
    {
        $credential = $this->enable2fa($this->admin);
        $expired = $this->g2fa->oathTotp($credential->secret, $this->g2fa->getTimestamp() - 20); // 10 minutes old

        foreach (['000000', $expired] as $bad) {
            $this->as($this->admin)->deleteJson('/api/2fa', ['password' => 'password', 'code' => $bad])
                ->assertUnprocessable()->assertJsonPath('reason', 'invalid_code');
        }
        $this->assertDatabaseHas('two_factor_credentials', ['authenticatable_id' => $this->admin->id, 'enabled' => true]);
        $this->assertSame(2, CompanyAuditLog::where('action', 'two_factor.disable_refused')->count());

        // a valid TOTP authorises ONE sensitive operation only (replay = concurrent request with the same code)
        $code = $this->otp($credential);
        $this->as($this->admin)->postJson('/api/2fa/backup-codes', ['code' => $code])->assertOk();
        $this->as($this->admin)->deleteJson('/api/2fa', ['password' => 'password', 'code' => $code])->assertUnprocessable();
        $this->assertDatabaseHas('two_factor_credentials', ['authenticatable_id' => $this->admin->id, 'enabled' => true]);
    }

    public function test_3b_failures_are_throttled_per_account(): void
    {
        $credential = $this->enable2fa($this->admin);
        for ($i = 0; $i < 5; $i++) {
            $this->as($this->admin)->deleteJson('/api/2fa', ['password' => 'password', 'code' => '111111'])->assertUnprocessable();
        }
        // even a valid code is refused while blocked
        $this->as($this->admin)->deleteJson('/api/2fa', ['password' => 'password', 'code' => $this->otp($credential)])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('reason', 'too_many_attempts');
        $this->assertDatabaseHas('two_factor_credentials', ['authenticatable_id' => $this->admin->id, 'enabled' => true]);
    }

    // ── 4–5 : backup codes ────────────────────────────────────────────────────────────────────────────────

    public function test_4_5_regeneration_requires_a_factor_and_invalidates_the_old_codes(): void
    {
        Notification::fake();
        $credential = $this->enable2fa($this->admin);
        $old = $credential->backup_codes;

        $this->as($this->admin)->postJson('/api/2fa/backup-codes')->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->as($this->admin)->postJson('/api/2fa/backup-codes', ['code' => 'ZZZZ-ZZZZ'])->assertUnprocessable();
        $this->assertSame($old, $credential->fresh()->backup_codes, 'a refused attempt keeps the old codes');

        $res = $this->as($this->admin)->postJson('/api/2fa/backup-codes', ['code' => $old[0]])->assertOk()->assertHeader('Cache-Control');
        $new = $res->json('backup_codes');
        $this->assertCount(10, $new);
        $this->assertEmpty(array_intersect($old, $new));
        $this->assertSame($new, $credential->fresh()->backup_codes);

        // the old codes no longer work anywhere ; a new one does (and only once)
        $service = app(TwoFactorService::class);
        $this->assertFalse($service->verify($this->admin, $old[1]));
        $this->assertTrue($service->verify($this->admin, $new[0]));
        $this->assertFalse($service->verify($this->admin, $new[0]));

        // codes are shown once : the status endpoint never returns them
        $this->assertArrayNotHasKey('backup_codes', $this->as($this->admin)->getJson('/api/2fa/status')->json());
        $log = CompanyAuditLog::where('action', 'two_factor.backup_codes_regenerated')->sole();
        $this->assertSame('backup_code', $log->metadata['factor']);
        $this->assertNoSecretInAudit(array_merge($old, $new));
        Notification::assertSentTo($this->admin, AccountSecurityNotification::class, fn ($n) => $n->event === 'backup_codes_regenerated');
    }

    public function test_5b_a_backup_code_cannot_serve_twice_and_an_expired_session_is_refused(): void
    {
        $credential = $this->enable2fa($this->admin);
        $code = $credential->backup_codes[0];
        $this->as($this->admin)->postJson('/api/2fa/backup-codes', ['code' => $code])->assertOk();
        $this->as($this->admin)->deleteJson('/api/2fa', ['password' => 'password', 'code' => $code])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-Company-Id' => $this->company->id])->postJson('/api/2fa/backup-codes', ['code' => $code])->assertUnauthorized();
        $this->deleteJson('/api/platform/2fa', ['password' => 'password', 'code' => '123456'])->assertUnauthorized();
    }

    public function test_platform_scope_applies_the_same_rules(): void
    {
        $admin = PlatformUser::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'p-838@platform.test', 'password' => 'password']);
        $credential = $this->enable2fa($admin);

        $this->actingAs($admin, 'platform')->deleteJson('/api/platform/2fa', ['password' => 'password'])->assertUnprocessable();
        $this->actingAs($admin, 'platform')->postJson('/api/platform/2fa/backup-codes')->assertUnprocessable();
        $this->actingAs($admin, 'platform')->postJson('/api/platform/2fa/backup-codes', ['code' => $this->otp($credential)])->assertOk();
        $this->actingAs($admin, 'platform')->deleteJson('/api/platform/2fa', ['password' => 'password', 'code' => $credential->fresh()->backup_codes[0]])->assertOk();

        $this->assertSame(['two_factor.backup_codes_regenerated', 'two_factor.disabled'],
            PlatformAuditLog::where('target_id', (string) $admin->id)->whereIn('action', ['two_factor.backup_codes_regenerated', 'two_factor.disabled'])->orderBy('id')->pluck('action')->all());
        // a Platform session never reaches the Company 2FA routes (and vice versa)
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'platform')->deleteJson('/api/2fa', ['password' => 'password', 'code' => '123456'])->assertUnauthorized();
    }

    // ── 6–8 : member passwords ────────────────────────────────────────────────────────────────────────────

    public function test_6_member_password_routes_need_members_credentials(): void
    {
        $other = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $other->id, 'role' => 'user']);
        $id = $this->company->memberships()->where('user_id', $other->id)->value('id');

        $this->as($this->member)->postJson("/api/company/members/{$id}/reset-password")->assertForbidden();
        $this->as($this->member)->putJson("/api/company/members/{$id}/password", ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertForbidden();
        $this->assertSame(0, CompanyAuditLog::whereIn('action', ['member.password_set', 'member.password_reset_sent'])->count());
    }

    public function test_7_an_admin_cannot_touch_a_member_of_another_company(): void
    {
        $foreign = Company::create(['name' => 'Foreign', 'slug' => 'foreign-co', 'jobdomain_key' => 'logistique']);
        $victim = User::factory()->create(['password' => bcrypt('password')]);
        $foreignMembership = $foreign->memberships()->create(['user_id' => $victim->id, 'role' => 'user']);

        $this->as($this->admin)->putJson("/api/company/members/{$foreignMembership->id}/password", ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertNotFound();
        $this->as($this->admin)->postJson("/api/company/members/{$foreignMembership->id}/reset-password")->assertNotFound();
        // nor reach it by naming the other company
        $this->actingAs($this->admin)->withHeaders(['X-Company-Id' => $foreign->id])
            ->putJson("/api/company/members/{$foreignMembership->id}/password", ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertForbidden();
        $this->assertTrue(Hash::check('password', $victim->fresh()->password));
    }

    public function test_8_admin_set_password_ends_sessions_notifies_and_audits_without_the_value(): void
    {
        Notification::fake();
        $this->fakeSession('member-browser-1', $this->member->id);
        $this->fakeSession('member-browser-2', $this->member->id);
        $this->fakeSession('admin-browser', $this->admin->id);
        $remember = $this->member->getRememberToken();

        $this->as($this->admin)->putJson("/api/company/members/{$this->memberMembership->id}/password", ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable(); // robustness rules
        $this->as($this->admin)->putJson("/api/company/members/{$this->memberMembership->id}/password", ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->member->id)->count(), 'the member is signed out everywhere');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->admin->id)->count(), 'the admin keeps the session');
        $this->assertNotSame($remember, $this->member->fresh()->getRememberToken());
        $log = CompanyAuditLog::where('action', 'member.password_set')->sole();
        $this->assertSame([$this->admin->id, (string) $this->member->id, 'success', 2], [$log->actor_id, $log->target_id, $log->metadata['result'], $log->metadata['sessions_ended']]);
        Notification::assertSentTo($this->member, AccountSecurityNotification::class, fn ($n) => $n->event === 'password_set_by_admin');
        $this->assertNoSecretInAudit([self::STRONG]);

        // the reset link (recommended path) is audited too, never with its token
        $this->as($this->admin)->postJson("/api/company/members/{$this->memberMembership->id}/reset-password")->assertOk();
        $this->assertTrue(CompanyAuditLog::where('action', 'member.password_reset_sent')->where('target_id', (string) $this->member->id)->exists());
    }

    public function test_8b_completing_a_reset_link_ends_every_session(): void
    {
        $this->fakeSession('stolen', $this->member->id);
        $token = Password::broker('users')->createToken($this->member);
        $this->postJson('/api/reset-password', ['token' => $token, 'email' => $this->member->email, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->member->id)->count());
        $this->assertNoSecretInAudit([$token, self::STRONG]);
    }

    public function test_8c_platform_admin_set_password_ends_the_targets_sessions_and_reset_is_audited(): void
    {
        Notification::fake();
        $operator = PlatformUser::create(['first_name' => 'O', 'last_name' => 'P', 'email' => 'op-838@platform.test', 'password' => 'password']);
        $operator->roles()->attach(PlatformRole::where('key', 'super_admin')->value('id'));
        $target = PlatformUser::create(['first_name' => 'T', 'last_name' => 'G', 'email' => 'tg-838@platform.test', 'password' => 'password']);
        $this->fakeSession('target-browser', null, $target->id);

        $this->actingAs($operator, 'platform')->putJson("/api/platform/platform-users/{$target->id}/password", ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('platform_user_id', $target->id)->count());
        $this->assertSame('success', PlatformAuditLog::where('action', 'platform_user.password_set')->sole()->metadata['result']);
        Notification::assertSentTo($target, AccountSecurityNotification::class);

        $this->actingAs($operator, 'platform')->postJson("/api/platform/platform-users/{$target->id}/reset-password")->assertOk();
        $this->assertTrue(PlatformAuditLog::where('action', 'platform_user.password_reset_sent')->where('target_id', (string) $target->id)->exists());
        $this->assertNoSecretInAudit([self::STRONG]);

        // a Platform operator has no route to a Company member's password
        $this->actingAs($operator, 'platform')->putJson("/api/company/members/{$this->memberMembership->id}/password", ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertUnauthorized();
    }

    // ── 10 : regression — self-service change keeps the current session, ends the others ───────────────

    public function test_10_own_password_change_keeps_this_session_and_ends_the_others(): void
    {
        Notification::fake();
        $this->fakeSession('other-device', $this->admin->id);

        $this->as($this->admin)->putJson('/api/profile/password', ['current_password' => 'password', 'password' => 'weakpass1', 'password_confirmation' => 'weakpass1'])
            ->assertUnprocessable(); // the single password policy now applies here too
        $this->as($this->admin)->putJson('/api/profile/password', ['current_password' => 'password', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
        $this->as($this->admin)->getJson('/api/2fa/status')->assertOk(); // still signed in
        $this->assertSame(1, CompanyAuditLog::where('action', 'user.password_changed')->count());
        $this->assertNoSecretInAudit([self::STRONG]);
    }
}
