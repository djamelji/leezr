<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyRole;
use App\Company\RBAC\MembershipSecurityPolicy;
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
 * M7 LOT 2 §4 — an EXISTING account is never silently attached to a company. Invitation sent →
 * acceptance by the recipient → membership activated. Exercised through the real HTTP paths
 * (Members invitation, Workforce access grant, the recipient's own endpoints).
 */
class M7Lot2InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase, SetsUpCompanyRbac, ActivatesCompanyModules;

    private Company $company;
    private Company $other;
    private User $owner;
    private User $existing;      // pre-existing account, active member of $other only
    private CompanyRole $adminRole;
    private CompanyRole $memberRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        Notification::fake();

        $modules = ['workforce'];
        \App\Core\Jobdomains\Jobdomain::where('key', 'logistique')->update(['default_modules' => $modules]);
        $this->company = Company::create(['name' => 'A Co', 'slug' => 'a-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        $this->other = Company::create(['name' => 'B Co', 'slug' => 'b-'.uniqid(), 'jobdomain_key' => 'logistique', 'plan_key' => 'pro']);
        foreach ([$this->company, $this->other] as $c) {
            $this->activateCompanyModules($c);
            \App\Core\Modules\CompanyModuleActivationReason::firstOrCreate(
                ['company_id' => $c->id, 'module_key' => 'workforce'],
                ['reason' => \App\Core\Modules\CompanyModuleActivationReason::REASON_DIRECT],
            );
        }
        $this->adminRole = $this->setUpCompanyRbac($this->company);
        $otherAdmin = $this->setUpCompanyRbac($this->other);
        $this->memberRole = CompanyRole::create(['company_id' => $this->company->id, 'key' => 'staff', 'name' => 'Staff', 'is_administrative' => false]);

        $this->owner = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);

        $this->existing = User::factory()->create(['email' => 'existing@example.test', 'first_name' => 'Eve', 'last_name' => 'Existing']);
        $this->other->memberships()->create(['user_id' => $this->existing->id, 'role' => 'user', 'company_role_id' => $otherAdmin->id]);
    }

    private function as(User $user, ?Company $company = null): static
    {
        $this->actingAs($user);

        return $company ? $this->withHeaders(['X-Company-Id' => $company->id]) : $this->withHeaders(['X-Company-Id' => '']);
    }

    private function invite(string $email, ?CompanyRole $role = null)
    {
        return $this->as($this->owner, $this->company)->postJson('/api/company/members', [
            'email' => $email, 'company_role_id' => ($role ?? $this->memberRole)->id,
        ]);
    }

    private function membership(User $user, ?Company $company = null): ?Membership
    {
        return Membership::withoutGlobalScopes()->where('company_id', ($company ?? $this->company)->id)->where('user_id', $user->id)->first();
    }

    public function test_inviting_an_existing_account_creates_a_pending_membership_without_access(): void
    {
        $this->invite('existing@example.test')->assertCreated();

        $m = $this->membership($this->existing);
        $this->assertNotNull($m);
        $this->assertTrue($m->isPendingAcceptance(), 'An existing account must not be activated without its acceptance.');
        $this->assertSame($this->owner->id, (int) $m->invited_by);
        Notification::assertSentTo($this->existing, CompanyInvitationNotification::class);
        Notification::assertNotSentTo($this->existing, ResetPassword::class);

        // No access to the inviting company, hidden from the switcher, no permission.
        $this->as($this->existing, $this->company)->getJson('/api/company/people')->assertForbidden();
        $ids = collect($this->as($this->existing)->getJson('/api/my-companies')->assertOk()->json('companies'))->pluck('id');
        $this->assertFalse($ids->contains($this->company->id));
        $this->assertTrue($ids->contains($this->other->id));
        $this->assertFalse($this->existing->fresh()->hasCompanyPermission($this->company, 'members.view'));

        // The other company is untouched.
        $otherM = $this->membership($this->existing, $this->other);
        $this->assertFalse($otherM->isSuspended());
        $this->assertFalse($otherM->isPendingAcceptance());

        // The admin sees the truth: pending acceptance, not an accepted invitation.
        $row = collect($this->as($this->owner, $this->company)->getJson('/api/company/people')->assertOk()->json('data'))
            ->firstWhere('user_id', $this->existing->id);
        $this->assertSame('pending_acceptance', $row['account_state']);
        $file = $this->as($this->owner, $this->company)->getJson('/api/company/people/user-'.$this->existing->id)->assertOk()->json('account');
        $this->assertSame('pending_acceptance', $file['state']);
        $this->assertFalse($file['has_access']);
    }

    public function test_recipient_lists_and_accepts_the_invitation(): void
    {
        $this->invite('existing@example.test')->assertCreated();
        $m = $this->membership($this->existing);

        $list = $this->as($this->existing)->getJson('/api/my-invitations')->assertOk()->json('invitations');
        $this->assertCount(1, $list);
        $this->assertSame($m->id, $list[0]['id']);
        $this->assertSame('A Co', $list[0]['company']['name']);
        $this->assertSame('Staff', $list[0]['role']);

        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertOk();

        $m = $this->membership($this->existing);
        $this->assertFalse($m->isPendingAcceptance());
        $this->assertFalse($m->isSuspended());
        $this->assertNull($m->suspension_reason);
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'member.invitation_accepted', 'target_id' => (string) $m->id]);
        $this->as($this->existing, $this->company)->getJson('/api/my-companies')->assertOk();
        $this->assertTrue(collect($this->as($this->existing)->getJson('/api/my-companies')->json('companies'))->pluck('id')->contains($this->company->id));
        $this->assertCount(0, $this->as($this->existing)->getJson('/api/my-invitations')->json('invitations'));
        // Accepting twice is not possible.
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/accept")->assertNotFound();
    }

    public function test_recipient_declines_and_membership_is_removed(): void
    {
        $this->invite('existing@example.test')->assertCreated();
        $m = $this->membership($this->existing);

        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/decline")->assertOk();

        $this->assertNull($this->membership($this->existing));
        $this->assertNotNull($this->membership($this->existing, $this->other));
        $this->assertDatabaseHas('company_audit_logs', ['company_id' => $this->company->id, 'action' => 'member.invitation_declined']);
    }

    public function test_another_user_cannot_accept_or_decline_on_behalf_of_the_recipient(): void
    {
        $this->invite('existing@example.test')->assertCreated();
        $m = $this->membership($this->existing);
        $stranger = User::factory()->create();

        $this->as($stranger)->postJson("/api/my-invitations/{$m->id}/accept")->assertNotFound();
        $this->as($stranger)->postJson("/api/my-invitations/{$m->id}/decline")->assertNotFound();
        $this->as($this->owner)->postJson("/api/my-invitations/{$m->id}/accept")->assertNotFound();
        $this->assertTrue($this->membership($this->existing)->isPendingAcceptance());

        // A plain suspended (non-pending) membership is NOT an invitation the user can self-accept.
        $suspended = User::factory()->create();
        $sm = $this->company->memberships()->create(['user_id' => $suspended->id, 'role' => 'user', 'company_role_id' => $this->memberRole->id, 'suspended_at' => now()]);
        $this->as($suspended)->postJson("/api/my-invitations/{$sm->id}/accept")->assertNotFound();
        $this->assertTrue($sm->fresh()->isSuspended());
    }

    public function test_admin_cannot_bypass_acceptance(): void
    {
        $employee = Employee::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'first_name' => 'Eve', 'last_name' => 'Existing',
            'email' => 'existing@example.test', 'status' => 'active', 'hire_date' => '2026-01-01',
        ]);

        // Workforce access grant on an existing e-mail → pending too.
        $this->as($this->owner, $this->company)->postJson("/api/workforce/employees/{$employee->id}/access", ['email' => 'existing@example.test'])
            ->assertOk()->assertJsonPath('state', 'pending_acceptance')->assertJsonPath('can_restore', false)->assertJsonPath('can_resend', true);
        $m = $this->membership($this->existing);
        $this->assertTrue($m->isPendingAcceptance());
        $this->assertSame($this->existing->id, $employee->fresh()->user_id);
        Notification::assertSentToTimes($this->existing, CompanyInvitationNotification::class, 1);

        // Restore = would activate without acceptance → refused.
        $this->as($this->owner, $this->company)->postJson("/api/workforce/employees/{$employee->id}/access/restore")->assertStatus(422);
        $this->assertTrue($this->membership($this->existing)->isPendingAcceptance());

        // Credentials of a not-yet-accepted pre-existing account are out of reach.
        $pwd = $this->existing->password;
        $this->as($this->owner, $this->company)->putJson("/api/company/members/{$m->id}/password", ['password' => 'N3w-Str0ng-Pass!x', 'password_confirmation' => 'N3w-Str0ng-Pass!x'])->assertStatus(422);
        $this->as($this->owner, $this->company)->postJson("/api/company/members/{$m->id}/reset-password")->assertStatus(422);
        $this->assertSame($pwd, $this->existing->fresh()->password);
        Notification::assertNotSentTo($this->existing, ResetPassword::class);

        // Resend re-sends the invitation (not a password link).
        $this->as($this->owner, $this->company)->postJson("/api/workforce/employees/{$employee->id}/access/resend")->assertOk();
        Notification::assertSentToTimes($this->existing, CompanyInvitationNotification::class, 2);

        // Decline also unlinks the HR record (HR data kept).
        $this->as($this->existing)->postJson("/api/my-invitations/{$m->id}/decline")->assertOk();
        $this->assertNull($employee->fresh()->user_id);
        $this->assertNotNull($employee->fresh());
    }

    public function test_admin_can_cancel_a_pending_invitation(): void
    {
        $this->invite('existing@example.test')->assertCreated();
        $m = $this->membership($this->existing);

        $this->as($this->owner, $this->company)->deleteJson("/api/company/members/{$m->id}")->assertOk();
        $this->assertNull($this->membership($this->existing));
        $this->assertCount(0, $this->as($this->existing)->getJson('/api/my-invitations')->json('invitations'));
    }

    public function test_new_account_keeps_the_historical_flow(): void
    {
        $this->invite('brand-new@example.test')->assertCreated();

        $user = User::where('email', 'brand-new@example.test')->firstOrFail();
        $m = $this->membership($user);
        $this->assertFalse($m->isPendingAcceptance());
        $this->assertFalse($m->isSuspended());
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertNotSentTo($user, CompanyInvitationNotification::class);
    }

    public function test_pending_admin_invitation_does_not_count_as_an_active_admin(): void
    {
        $admin = User::factory()->create();
        $adminM = $this->company->memberships()->create(['user_id' => $admin->id, 'role' => 'user', 'company_role_id' => $this->adminRole->id]);
        // Neutralise the owner as an "other admin" for this check.
        Membership::withoutGlobalScopes()->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['suspended_at' => now()]);
        $this->assertTrue(MembershipSecurityPolicy::isLastActiveAdmin($this->company, $adminM));
        Membership::withoutGlobalScopes()->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['suspended_at' => null]);

        $this->invite('existing@example.test', $this->adminRole)->assertCreated();
        $this->assertTrue($this->membership($this->existing)->isPendingAcceptance());

        Membership::withoutGlobalScopes()->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['suspended_at' => now()]);
        $this->assertTrue(MembershipSecurityPolicy::isLastActiveAdmin($this->company, $adminM), 'A pending (unaccepted) admin must not make the last active admin removable.');
    }

    public function test_invitations_endpoints_require_authentication(): void
    {
        $this->getJson('/api/my-invitations')->assertUnauthorized();
    }
}
