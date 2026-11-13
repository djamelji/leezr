<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Company\Security\MemberQuota;
use App\Company\Security\MembershipInvitation;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Modules\Core\Members\UseCases\InviteMemberUseCase;
use App\Notifications\Members\CompanyInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * M7 LOT 4 §7 — the WHOLE invitation cycle through the real endpoints, and the three notions that
 * must never be confused :
 *   (1) expiry of the INVITATION (acceptance by a pre-existing account) ;
 *   (2) expiry of the password-definition LINK (account created by the invitation) ;
 *   (3) end of the MEMBERSHIP (suspension / removal).
 */
class M7Lot4InvitationLifecycleTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;   // pro : no member limit
    private Company $small;     // starter : 5 members
    private Company $other;
    private User $owner;
    private User $existing;
    private CompanyRole $staff;
    private CompanyRole $smallStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        $this->company = Company::create(['name' => 'A Co', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->small = Company::create(['name' => 'Small Co', 'slug' => 's-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'starter']);
        $this->other = Company::create(['name' => 'B Co', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        foreach ([$this->company, $this->small, $this->other] as $c) {
            $this->activateCompanyModules($c);
            $this->setUpCompanyRbac($c);
        }
        $this->staff = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'staff', 'name' => 'Staff', 'is_administrative' => false]);
        $this->smallStaff = CompanyRole::create(['company_id' => $this->small->id, 'key' => 'staff', 'name' => 'Staff', 'is_administrative' => false]);

        $this->owner = User::factory()->create();
        foreach ([$this->company, $this->small] as $c) {
            $c->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        }
        $this->existing = User::factory()->create(['email' => 'existing@example.test']);
        $this->other->memberships()->create(['user_id' => $this->existing->id, 'role' => 'owner']);
        $this->assertSame(5, MemberQuota::limit($this->small));
    }

    private function as(User $user, ?Company $company = null): static
    {
        $this->actingAs($user);

        return $this->withHeaders(['X-Company-Id' => $company ? $company->id : '']);
    }

    private function invite(string $email, ?Company $company = null)
    {
        $company ??= $this->company;

        return $this->as($this->owner, $company)->postJson('/api/company/members', [
            'email' => $email, 'first_name' => 'In', 'last_name' => 'Vited',
            'company_role_id' => ($company->is($this->small) ? $this->smallStaff : $this->staff)->id,
        ]);
    }

    private function membership(User $user, ?Company $company = null): ?Membership
    {
        return Membership::withoutGlobalScopes()->where('company_id', ($company ?? $this->company)->id)->where('user_id', $user->id)->get()->first();
    }

    private function file(User $subject, ?Company $company = null): array
    {
        return $this->as($this->owner, $company ?? $this->company)->getJson("/api/company/people/user-{$subject->id}")->assertOk()->json('account');
    }

    private function breakSmtp(): void
    {
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        app('mail.manager')->forgetMailers();
    }

    /** A pending acceptance as ADR-768 created it : no stored deadline, no delivery state. */
    private function legacyPending(User $user, Company $company, int $daysAgo): Membership
    {
        $m = $company->memberships()->create(['user_id' => $user->id, 'role' => 'user', 'company_role_id' => ($company->is($this->small) ? $this->smallStaff : $this->staff)->id]);
        $m->forceFill([
            'acceptance_pending_at' => now()->subDays($daysAgo), 'invited_by' => $this->owner->id,
            'suspended_at' => now()->subDays($daysAgo), 'suspended_by' => $this->owner->id, 'suspension_reason' => MembershipInvitation::REASON,
            'invitation_expires_at' => null,
        ])->save();

        return $m;
    }

    // ═══ Full cycle — account created by the invitation ═════════════════════

    public function test_new_account_cycle_invite_activate_and_never_dead_end_on_the_60_minute_link(): void
    {
        Notification::fake();
        $email = 'new-'.uniqid().'@example.test';

        // 1. Invitation : account created, no access gate other than the password.
        $res = $this->invite($email)->assertStatus(201);
        $res->assertJsonPath('pending_acceptance', false)->assertJsonPath('invitation.kind', 'account_setup')
            ->assertJsonPath('invitation.expires_at', null)->assertJsonPath('invitation.expired', false);
        $invitee = User::where('email', $email)->firstOrFail();
        Notification::assertSentTo($invitee, ResetPassword::class);
        $firstToken = null;
        Notification::assertSentTo($invitee, ResetPassword::class, function ($n) use (&$firstToken) { $firstToken = $n->token; return true; });

        $acc = $this->file($invitee);
        $this->assertSame('invited', $acc['state']);
        $this->assertFalse($acc['invitation']['link_expired']);
        $this->assertNotNull($acc['invitation']['link_expires_at']);

        // 2. The 60-minute link expires. The INVITATION does not : the account is still invited.
        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', ['email' => $email, 'token' => $firstToken, 'password' => 'Str0ng!Passw0rd#', 'password_confirmation' => 'Str0ng!Passw0rd#'])
            ->assertStatus(422);
        $this->assertNull($invitee->fresh()->password_set_at);
        $acc = $this->file($invitee);
        $this->assertSame(['invited', true, false, null], [$acc['state'], $acc['invitation']['link_expired'], $acc['invitation']['expired'], $acc['invitation']['expires_at']]);
        $this->assertNull($this->membership($invitee)->suspended_at, 'the membership itself never expired');

        // 3a. Way out for the PERSON : « forgot password » works for an account never activated.
        $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();
        Notification::assertSentToTimes($invitee, ResetPassword::class, 2);
        $selfToken = null;
        Notification::assertSentTo($invitee, ResetPassword::class, function ($n) use (&$selfToken, $firstToken) {
            if ($n->token !== $firstToken) { $selfToken = $n->token; }

            return true;
        });
        $this->assertNotNull($selfToken);

        // 3b. Way out for the ADMINISTRATOR : he sees the expired link and re-sends (no quota : the seat is already held).
        $this->travel(2)->minutes(); // broker throttle
        $resent = $this->as($this->owner, $this->company)->postJson('/api/company/members/'.$this->membership($invitee)->id.'/resend-invitation')->assertOk();
        $resent->assertJsonPath('invitation.kind', 'account_setup')->assertJsonPath('invitation.delivery', 'sent')->assertJsonPath('invitation.link_expired', false);
        Notification::assertSentToTimes($invitee, ResetPassword::class, 3);
        $adminToken = null;
        Notification::assertSentTo($invitee, ResetPassword::class, function ($n) use (&$adminToken, $firstToken, $selfToken) {
            if (! in_array($n->token, [$firstToken, $selfToken], true)) { $adminToken = $n->token; }

            return true;
        });

        // 4. Activation with the fresh link : the account becomes active, nothing left to send.
        $this->postJson('/api/reset-password', ['email' => $email, 'token' => $adminToken, 'password' => 'Str0ng!Passw0rd#', 'password_confirmation' => 'Str0ng!Passw0rd#'])->assertOk();
        $acc = $this->file($invitee);
        $this->assertSame(['active', null], [$acc['state'], $acc['invitation']]);
        $this->as($this->owner, $this->company)->postJson('/api/company/members/'.$this->membership($invitee)->id.'/resend-invitation')->assertStatus(422);
        $this->as($invitee, $this->company)->getJson('/api/profile/space')->assertOk();
    }

    // ═══ Full cycle — pre-existing account ══════════════════════════════════

    public function test_existing_account_cycle_accept_decline_cancel(): void
    {
        Notification::fake();

        // Invitation → pending, deadline stored, e-mail sent, no access.
        $res = $this->invite($this->existing->email)->assertStatus(201);
        $res->assertJsonPath('pending_acceptance', true)->assertJsonPath('invitation.kind', 'acceptance')
            ->assertJsonPath('invitation.delivery', 'sent')->assertJsonPath('invitation.link_expires_at', null)->assertJsonPath('invitation.link_expired', false);
        Notification::assertSentTo($this->existing, CompanyInvitationNotification::class);
        $m = $this->membership($this->existing);
        $this->as($this->existing, $this->company)->getJson('/api/profile/space')->assertStatus(403);
        $this->assertSame('pending_acceptance', $this->file($this->existing)['state']);

        // The recipient sees it, with its deadline, and accepts.
        $mine = $this->as($this->existing)->getJson('/api/my-invitations')->assertOk()->json('invitations');
        $this->assertSame([$m->id], array_column($mine, 'id'));
        $this->assertNotNull($mine[0]['expires_at']);
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertOk();
        $this->as($this->existing, $this->company)->getJson('/api/profile/space')->assertOk();
        $this->assertNull($this->file($this->existing)['invitation']);
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertStatus(404); // not replayable

        // (3) End of the MEMBERSHIP is another notion : suspension keeps the row, it is not an invitation state.
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/suspend")->assertOk();
        $acc = $this->file($this->existing);
        $this->assertSame(['suspended', null], [$acc['state'], $acc['invitation']]);
        $this->assertSame([], $this->as($this->existing)->getJson('/api/my-invitations')->json('invitations'), 'a suspension is not something the person can « accept »');
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertStatus(404);
        $this->as($this->owner, $this->company)->deleteJson("/api/company/members/{$m->id}")->assertOk();

        // Decline : the membership is removed, the other company untouched.
        $this->invite($this->existing->email)->assertStatus(201);
        $m2 = $this->membership($this->existing);
        $this->as($this->existing)->postJson("/api/my-invitations/{$m2->id}/decline")->assertOk();
        $this->assertNull($this->membership($this->existing));
        $this->assertNotNull($this->membership($this->existing, $this->other));

        // Cancellation by the administrator.
        $this->invite($this->existing->email)->assertStatus(201);
        $m3 = $this->membership($this->existing);
        $this->as($this->owner, $this->company)->deleteJson("/api/company/members/{$m3->id}")->assertOk();
        $this->assertSame([], $this->as($this->existing)->getJson('/api/my-invitations')->json('invitations'));

        foreach (['member.invitation_sent', 'member.invitation_accepted', 'member.invitation_declined'] as $action) {
            $this->assertTrue(CompanyAuditLog::where('company_id', $this->company->id)->where('action', $action)->exists(), $action);
        }
    }

    // ═══ Compatibility — invitations created before deadlines existed ═══════

    public function test_legacy_invitation_without_deadline_gets_a_derived_one_and_is_never_destroyed_silently(): void
    {
        Notification::fake();
        $ttl = MembershipInvitation::ttlDays();
        $freshUser = User::factory()->create();
        $this->other->memberships()->create(['user_id' => $freshUser->id, 'role' => 'user']);

        $old = $this->legacyPending($this->existing, $this->company, $ttl + 3);
        $recent = $this->legacyPending($freshUser, $this->company, 2);

        // Derived deadline = sent date + validity ; nothing rewritten.
        $this->assertNull($old->fresh()->invitation_expires_at);
        $this->assertTrue(MembershipInvitation::isExpired($old->fresh()));
        $this->assertFalse(MembershipInvitation::isExpired($recent->fresh()));
        $this->assertSame($recent->acceptance_pending_at->copy()->addDays($ttl)->toIso8601String(), MembershipInvitation::expiresAt($recent->fresh())->toIso8601String());

        // The old one : no longer offered, no longer acceptable, visible as expired, frees its seat.
        $this->assertSame([], $this->as($this->existing)->getJson('/api/my-invitations')->json('invitations'));
        $this->as($this->existing)->postJson("/api/my-invitations/{$old->id}/accept")->assertStatus(410)->assertJsonPath('code', 'invitation_expired');
        $this->assertNotNull($old->fresh()->suspended_at);
        $acc = $this->file($this->existing);
        $this->assertSame(['invitation_expired', true], [$acc['state'], $acc['invitation']['expired']]);
        $this->assertNotNull($acc['invitation']['expires_at']);
        $this->assertSame(2, MemberQuota::used($this->company), 'owner + recent legacy invitation ; the expired legacy one frees its seat');

        // The recent one : still acceptable, with a deadline shown to the recipient.
        $mine = $this->as($freshUser)->getJson('/api/my-invitations')->json('invitations');
        $this->assertSame([$recent->id], array_column($mine, 'id'));
        $this->assertNotNull($mine[0]['expires_at']);

        // Scheduled trace : audit written once, row kept, deadline flagged as derived.
        $this->artisan('members:invitations-expire')->assertSuccessful();
        $this->artisan('members:invitations-expire')->assertSuccessful();
        $events = CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'member.invitation_expired')->get();
        $this->assertCount(1, $events);
        $this->assertTrue((bool) ($events[0]->metadata['derived_deadline'] ?? false));
        $this->assertNotNull(Membership::withoutGlobalScopes()->find($old->id), 'never destroyed');
        $this->assertNull($old->fresh()->invitation_expires_at, 'no silent rewrite of the deadline');
        $this->assertNotNull($old->fresh()->invitation_expired_at);

        // Renewal stores a real deadline ; the recipient can then accept.
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$old->id}/resend-invitation")->assertOk()->assertJsonPath('invitation.expired', false);
        $this->assertNotNull($old->fresh()->invitation_expires_at);
        $this->as($this->existing)->postJson("/api/my-invitations/{$old->id}/accept")->assertOk();

        // The recent legacy one expires too, at its derived date.
        $this->travel($ttl)->days();
        $this->as($freshUser)->postJson("/api/my-invitations/{$recent->id}/accept")->assertStatus(410);
    }

    // ═══ Queue stopped / retries ════════════════════════════════════════════

    public function test_queue_without_worker_reports_queued_never_sent_then_worker_delivers(): void
    {
        Notification::fake();
        config(['queue.default' => 'database']);

        $res = $this->invite($this->existing->email)->assertStatus(201);
        $res->assertJsonPath('invitation.delivery', 'queued')->assertJsonPath('invitation.sent_at', null);
        Notification::assertNothingSent();
        $this->assertSame(1, DB::table('jobs')->count());
        $m = $this->membership($this->existing);
        $acc = $this->file($this->existing);
        $this->assertSame(['queued', null], [$acc['invitation']['delivery'], $acc['invitation']['sent_at']]);

        // A re-send while still queued says so — in the data AND in the message.
        $again = $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/resend-invitation")->assertOk();
        $again->assertJsonPath('invitation.delivery', 'queued');
        $this->assertStringNotContainsStringIgnoringCase('invitation sent', $again->json('message'));

        // The worker starts : delivered once per job, state `sent`.
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--memory' => 4096])->assertSuccessful();
        $this->assertSame('sent', $m->fresh()->invitation_delivery_status);
        $this->assertNotNull($m->fresh()->invitation_sent_at);
        Notification::assertSentTo($this->existing, CompanyInvitationNotification::class);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_worker_retries_then_fails_for_good_and_a_manual_resend_recovers(): void
    {
        config(['queue.default' => 'database']);
        $this->breakSmtp();

        $this->invite($this->existing->email)->assertStatus(201)->assertJsonPath('invitation.delivery', 'queued');
        $m = $this->membership($this->existing);

        // Attempts 1 and 2 : failed, released with backoff. Attempt 3 : definitive failure.
        foreach ([1, 2, 3] as $attempt) {
            $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();
            $this->assertSame('failed', $m->fresh()->invitation_delivery_status, "attempt {$attempt}");
            $this->assertSame($attempt < 3 ? 1 : 0, DB::table('jobs')->count(), "queue after attempt {$attempt}");
            $this->travel(6)->minutes();
        }
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'visible to operations, replayable');
        $this->assertNotEmpty($m->fresh()->invitation_delivery_error);
        $this->assertSame(3, CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'member.invitation_delivery_failed')->count());
        $this->assertSame('failed', $this->file($this->existing)['invitation']['delivery']);
        $this->assertNull($m->fresh()->invitation_sent_at);

        // SMTP is back : the manual re-send recovers.
        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/resend-invitation")->assertOk();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();
        $this->assertSame('sent', $m->fresh()->invitation_delivery_status);
    }

    // ═══ Quota ══════════════════════════════════════════════════════════════

    public function test_quota_is_enforced_inside_the_use_case_not_only_by_the_controller(): void
    {
        Notification::fake();
        for ($i = 0; $i < 4; $i++) {
            $this->invite("seat{$i}@example.test", $this->small)->assertStatus(201);
        }
        $this->assertTrue(MemberQuota::isFull($this->small));
        $count = Membership::withoutGlobalScopes()->where('company_id', $this->small->id)->count();

        // Any caller of the use case (another controller, a job, a race that passed the HTTP pre-check).
        try {
            app(InviteMemberUseCase::class)->execute($this->small, ['email' => 'overflow@example.test', 'company_role_id' => $this->smallStaff->id], $this->owner);
            $this->fail('the use case added a membership past the limit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('access', $e->errors());
        }
        $this->assertSame($count, Membership::withoutGlobalScopes()->where('company_id', $this->small->id)->count());
        $this->assertNull(User::where('email', 'overflow@example.test')->first(), 'no dangling account either');
    }

    public function test_reinviting_the_email_of_an_expired_invitation_renews_it_instead_of_a_dead_end(): void
    {
        Notification::fake();
        $this->invite($this->existing->email, $this->small)->assertStatus(201);
        $m = $this->membership($this->existing, $this->small);

        // Still pending : inviting again is refused (nothing to renew).
        $this->invite($this->existing->email, $this->small)->assertStatus(422);

        $this->travel(MembershipInvitation::ttlDays() + 1)->days();
        $this->assertSame(1, MemberQuota::used($this->small));

        // Expired : the same form renews the SAME invitation (no duplicate), with a new deadline.
        $res = $this->invite($this->existing->email, $this->small)->assertStatus(201);
        $res->assertJsonPath('member.id', $m->id)->assertJsonPath('pending_acceptance', true)->assertJsonPath('invitation.expired', false);
        $this->assertSame(1, Membership::withoutGlobalScopes()->where('company_id', $this->small->id)->where('user_id', $this->existing->id)->count());
        $this->assertSame(2, MemberQuota::used($this->small));
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertOk();
    }

    public function test_expired_invitation_cannot_be_renewed_past_the_limit_by_either_path(): void
    {
        Notification::fake();
        $this->invite($this->existing->email, $this->small)->assertStatus(201);
        $m = $this->membership($this->existing, $this->small);
        $this->travel(MembershipInvitation::ttlDays() + 1)->days();

        for ($i = 0; $i < 4; $i++) {
            $this->invite("fill{$i}@example.test", $this->small)->assertStatus(201);
        }
        $this->assertTrue(MemberQuota::isFull($this->small));

        $this->as($this->owner, $this->small)->postJson("/api/company/members/{$m->id}/resend-invitation")->assertStatus(422);
        $this->invite($this->existing->email, $this->small)->assertStatus(422);
        $this->assertTrue(MembershipInvitation::isExpired($m->fresh()));
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertStatus(410);
    }

    public function test_never_activated_account_holds_its_seat_until_removed_and_the_displayed_quota_matches_the_rule(): void
    {
        Notification::fake();
        $this->invite('ghost@example.test', $this->small)->assertStatus(201);
        $this->invite($this->existing->email, $this->small)->assertStatus(201);
        $ghost = User::where('email', 'ghost@example.test')->firstOrFail();

        $this->travel(MembershipInvitation::ttlDays() + 30)->days();

        // The acceptance expired (seat freed) ; the never-activated ACCOUNT still holds its seat.
        $this->assertSame(2, MemberQuota::used($this->small)); // owner + ghost
        $this->assertSame('invited', $this->file($ghost, $this->small)['state']);

        // The company profile shows the SAME figure as the rule that blocks invitations.
        $profile = $this->as($this->owner, $this->small)->getJson('/api/company')->assertOk();
        $quota = $profile->json('member_quota') ?? $profile->json('company.member_quota') ?? data_get($profile->json(), 'data.member_quota');
        $this->assertSame(['current' => 2, 'limit' => 5], $quota);

        // Removing the ghost frees the seat.
        $this->as($this->owner, $this->small)->deleteJson('/api/company/members/'.$this->membership($ghost, $this->small)->id)->assertOk();
        $this->assertSame(1, MemberQuota::used($this->small));
    }
}
