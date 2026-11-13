<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Company\Security\MemberQuota;
use App\Company\Security\MembershipInvitation;
use App\Core\Audit\CompanyAuditLog;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Workforce\Employee;
use App\Notifications\Members\CompanyInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActivatesCompanyModules;
use Tests\Support\SetsUpCompanyRbac;
use Tests\TestCase;

/**
 * M7 LOT 3 §10 — stabilisation of the invitations introduced by ADR-768, through the real HTTP paths:
 *  1. a mail transport failure is never an HTTP 500 nor a false « e-mail sent » ;
 *  2. a pending invitation expires (configurable) and can no longer be accepted ;
 *  3. ONE quota rule : pending + accepted hold a seat, declined / cancelled / expired free it.
 */
class M7Lot3InvitationStabilizationTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;   // pro plan (no member limit)
    private Company $small;     // starter plan (5 members)
    private Company $other;
    private User $owner;
    private User $existing;
    private CompanyRole $staff;
    private CompanyRole $smallStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);

        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => ['workforce']]);
        $this->company = Company::create(['name' => 'A Co', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->small = Company::create(['name' => 'Small Co', 'slug' => 's-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'starter']);
        $this->other = Company::create(['name' => 'B Co', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        foreach ([$this->company, $this->small, $this->other] as $c) {
            $this->activateCompanyModules($c);
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(
                ['company_id' => $c->id, 'module_key' => 'workforce'],
                ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT],
            );
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

    /** A mail transport that cannot connect (nothing listens on port 1) — a real SMTP failure. */
    private function breakSmtp(): void
    {
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        app('mail.manager')->forgetMailers();
    }

    private function repairSmtp(): void
    {
        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
    }

    // ═══ 1. Mail delivery ═══════════════════════════════════════════════════

    public function test_smtp_outage_on_existing_account_invitation_is_not_a_500_nor_a_false_confirmation(): void
    {
        $this->breakSmtp();

        $res = $this->invite($this->existing->email)->assertStatus(201);
        $res->assertJsonPath('pending_acceptance', true)->assertJsonPath('invitation.delivery', 'failed');
        $this->assertNotEmpty($res->json('invitation.error'));
        $this->assertNull($res->json('invitation.sent_at'));

        // The invitation exists (pending, no access) and its delivery failure is persisted + audited.
        $m = $this->membership($this->existing);
        $this->assertNotNull($m);
        $this->assertTrue($m->isPendingAcceptance());
        $this->assertSame('failed', $m->invitation_delivery_status);
        $this->assertTrue(CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'member.invitation_delivery_failed')->exists());
        $this->as($this->owner, $this->company)->getJson("/api/company/people/user-{$this->existing->id}")
            ->assertOk()->assertJsonPath('account.invitation.delivery', 'failed');

        // Manual retry while SMTP is still down : still honest, still no 500.
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/resend-invitation")
            ->assertOk()->assertJsonPath('invitation.delivery', 'failed');

        // SMTP is back : the retry delivers and the state says so.
        $this->repairSmtp();
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/resend-invitation")
            ->assertOk()->assertJsonPath('invitation.delivery', 'sent');
        $this->assertSame('sent', $m->fresh()->invitation_delivery_status);
        $this->assertNotNull($m->fresh()->invitation_sent_at);
        $this->assertNull($m->fresh()->invitation_delivery_error);
    }

    public function test_smtp_outage_on_new_account_invitation_is_not_a_500(): void
    {
        $this->breakSmtp();
        $email = 'brand-new-'.uniqid().'@example.test';

        $res = $this->invite($email)->assertStatus(201);
        $res->assertJsonPath('pending_acceptance', false)->assertJsonPath('invitation.delivery', 'failed');

        $user = User::where('email', $email)->firstOrFail();
        $m = $this->membership($user);
        $this->assertSame('failed', $m->invitation_delivery_status);

        $this->repairSmtp();
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/resend-invitation")
            ->assertOk()->assertJsonPath('invitation.delivery', 'sent');
    }

    public function test_smtp_outage_on_workforce_grant_is_not_a_500(): void
    {
        $this->breakSmtp();
        $emp = Employee::create(['company_id' => $this->company->id, 'employee_number' => 'E1', 'first_name' => 'Wo', 'last_name' => 'Rker', 'email' => 'w@example.test', 'status' => 'active', 'hire_date' => '2026-01-01']);

        $this->as($this->owner, $this->company)->postJson("/api/workforce/employees/{$emp->id}/access", ['email' => $this->existing->email])
            ->assertOk()->assertJsonPath('state', 'pending_acceptance')->assertJsonPath('invitation.delivery', 'failed');

        $this->repairSmtp();
        $this->as($this->owner, $this->company)->postJson("/api/workforce/employees/{$emp->id}/access/resend")
            ->assertOk()->assertJsonPath('invitation.delivery', 'sent');
    }

    public function test_normal_delivery_is_recorded_as_sent_for_both_kinds(): void
    {
        Notification::fake();

        $this->invite($this->existing->email)->assertStatus(201)->assertJsonPath('invitation.delivery', 'sent')->assertJsonPath('invitation.kind', 'acceptance');
        Notification::assertSentTo($this->existing, CompanyInvitationNotification::class);
        $this->assertNotNull($this->membership($this->existing)->invitation_sent_at);

        $email = 'fresh-'.uniqid().'@example.test';
        $this->invite($email)->assertStatus(201)->assertJsonPath('invitation.delivery', 'sent')->assertJsonPath('invitation.kind', 'account_setup')
            ->assertJsonPath('invitation.expires_at', null);
        Notification::assertSentTo(User::where('email', $email)->first(), ResetPassword::class);
    }

    // ═══ 2. Expiry ══════════════════════════════════════════════════════════

    public function test_expired_invitation_cannot_be_accepted_and_can_be_renewed(): void
    {
        Notification::fake();
        config(['members.invitation_ttl_days' => 7]);

        $this->invite($this->existing->email)->assertStatus(201);
        $m = $this->membership($this->existing);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $m->invitation_expires_at->timestamp, 5);
        $this->as($this->existing)->getJson('/api/my-invitations')->assertOk()->assertJsonCount(1, 'invitations')
            ->assertJsonPath('invitations.0.expires_at', $m->invitation_expires_at->toIso8601String());

        $this->travel(8)->days();

        // Recipient : no longer offered, no longer acceptable (clear answer, not a 500 / not a 404).
        $this->as($this->existing)->getJson('/api/my-invitations')->assertOk()->assertJsonCount(0, 'invitations');
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertStatus(410)->assertJsonPath('code', 'invitation_expired');
        $this->assertTrue($m->fresh()->isPendingAcceptance());
        $this->as($this->existing, $this->company)->getJson('/api/company/people')->assertStatus(403);

        // Administrator : shown as expired.
        $this->as($this->owner, $this->company)->getJson("/api/company/people/user-{$this->existing->id}")->assertOk()
            ->assertJsonPath('account.state', 'invitation_expired')->assertJsonPath('account.has_access', false)
            ->assertJsonPath('account.invitation.expired', true);

        // Scheduled trace : audited once, idempotent, nothing deleted.
        $this->artisan('members:invitations-expire')->assertSuccessful();
        $this->artisan('members:invitations-expire')->assertSuccessful();
        $this->assertSame(1, CompanyAuditLog::where('company_id', $this->company->id)->where('action', 'member.invitation_expired')->count());
        $this->assertNotNull($m->fresh());

        // Renewal by the administrator gives a new deadline ; the recipient can accept again.
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/resend-invitation")->assertOk()
            ->assertJsonPath('invitation.expired', false);
        $this->assertTrue($m->fresh()->invitation_expires_at->isFuture());
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertOk();
        $this->as($this->existing, $this->company)->getJson('/api/company/people')->assertStatus(403); // staff role has no people permission…
        $this->assertFalse($m->fresh()->isSuspended());                                                  // …but the membership is active
    }

    public function test_expired_invitation_can_still_be_declined_or_cancelled(): void
    {
        Notification::fake();
        $this->invite($this->existing->email)->assertStatus(201);
        $m = $this->membership($this->existing);
        $this->travel(config('members.invitation_ttl_days') + 1)->days();

        $this->as($this->owner, $this->company)->deleteJson("/api/company/members/{$m->id}")->assertOk();
        $this->assertNull($this->membership($this->existing));
        $this->assertNotNull($this->membership($this->existing, $this->other)); // other company untouched
    }

    // ═══ 3. Quota ═══════════════════════════════════════════════════════════

    public function test_pending_invitations_hold_a_seat_and_cannot_be_multiplied_past_the_limit(): void
    {
        Notification::fake();
        $this->assertSame(5, MemberQuota::limit($this->small));

        // owner (1) + 4 invitations (existing accounts → pending) = 5 seats.
        $accounts = User::factory()->count(5)->create();
        foreach ($accounts->take(4) as $u) {
            $this->invite($u->email, $this->small)->assertStatus(201)->assertJsonPath('pending_acceptance', true);
        }
        $this->assertSame(5, MemberQuota::used($this->small));

        $this->invite($accounts[4]->email, $this->small)->assertStatus(422)->assertJsonPath('limit', 5)->assertJsonPath('current', 5);
        $this->invite('one-more-'.uniqid().'@example.test', $this->small)->assertStatus(422);
        $this->assertNull($this->membership($accounts[4], $this->small));

        // The Workforce path obeys the very same rule.
        $emp = Employee::create(['company_id' => $this->small->id, 'employee_number' => 'E9', 'first_name' => 'Wo', 'last_name' => 'Rker', 'email' => 'w9@example.test', 'status' => 'active', 'hire_date' => '2026-01-01']);
        $this->as($this->owner, $this->small)->postJson("/api/workforce/employees/{$emp->id}/access", ['email' => $accounts[4]->email])->assertStatus(422);
        $this->as($this->owner, $this->small)->postJson("/api/workforce/employees/{$emp->id}/access", ['email' => 'w9@example.test'])->assertStatus(422);
        $this->assertNull($emp->fresh()->user_id);

        // Another company's seats are independent.
        $this->assertSame(1, MemberQuota::used($this->company));
        $this->invite($accounts[4]->email)->assertStatus(201);
    }

    public function test_declined_cancelled_and_expired_invitations_free_their_seat(): void
    {
        Notification::fake();
        $accounts = User::factory()->count(6)->create();
        foreach ($accounts->take(4) as $u) {
            $this->invite($u->email, $this->small)->assertStatus(201);
        }
        $this->invite($accounts[4]->email, $this->small)->assertStatus(422);

        // Declined by the recipient → seat freed.
        $declined = $this->membership($accounts[0], $this->small);
        $this->as($accounts[0])->postJson("/api/my-invitations/{$declined->id}/decline")->assertOk();
        $this->assertSame(4, MemberQuota::used($this->small));
        $this->invite($accounts[4]->email, $this->small)->assertStatus(201);

        // Cancelled by the administrator → seat freed.
        $cancelled = $this->membership($accounts[1], $this->small);
        $this->as($this->owner, $this->small)->deleteJson("/api/company/members/{$cancelled->id}")->assertOk();
        $this->invite($accounts[5]->email, $this->small)->assertStatus(201);
        $this->assertSame(5, MemberQuota::used($this->small));

        // Accepted → still holds its seat.
        $accepted = $this->membership($accounts[2], $this->small);
        $this->as($accounts[2])->postJson("/api/my-invitations/{$accepted->id}/accept")->assertOk();
        $this->assertSame(5, MemberQuota::used($this->small));

        // Expired → frees the seats of the still-pending ones (3 pending : #3, #4, #5).
        $this->travel(config('members.invitation_ttl_days') + 1)->days();
        $this->assertSame(2, MemberQuota::used($this->small)); // owner + the accepted one
        $fresh = User::factory()->count(3)->create();
        foreach ($fresh as $u) {
            $this->invite($u->email, $this->small)->assertStatus(201);
        }
        $this->assertSame(5, MemberQuota::used($this->small));

        // …and an expired invitation cannot be renewed past the limit.
        $expired = $this->membership($accounts[3], $this->small);
        $this->as($this->owner, $this->small)->postJson("/api/company/members/{$expired->id}/resend-invitation")->assertStatus(422);
        $this->assertTrue(MembershipInvitation::isExpired($expired->fresh()));
    }
}
