<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Support\SupportMessage;
use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformUser;
use App\Platform\Models\PlatformRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();

        $this->company = Company::create([
            'name' => 'Support Co',
            'slug' => 'support-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);

        // Enable support module
        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        // Create admin role with all permissions
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'admin',
            'name' => 'Admin',
            'is_administrative' => true,
        ]);
        $role->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        $this->owner = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        $this->admin = PlatformUser::create([
            'first_name' => 'Support',
            'last_name' => 'Admin',
            'email' => 'support-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->admin->roles()->attach($superAdmin);
    }

    // ── Company-side tests ─────────────────────────────────

    public function test_company_user_can_create_ticket(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson('/api/support/tickets', [
                'subject' => 'Test ticket',
                'body' => 'I need help with something.',
                'category' => 'general',
                'priority' => 'normal',
            ])
            ->assertStatus(201)
            ->assertJsonFragment(['subject' => 'Test ticket']);

        $this->assertDatabaseHas('support_tickets', [
            'company_id' => $this->company->id,
            'subject' => 'Test ticket',
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('support_messages', [
            'sender_type' => 'company_user',
            'body' => 'I need help with something.',
        ]);
    }

    public function test_company_user_can_list_own_tickets(): void
    {
        SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'My ticket',
        ]);

        // Other company's ticket — should not appear
        $other = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);
        SupportTicket::create([
            'company_id' => $other->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Other ticket',
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/support/tickets')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('My ticket', $response->json('data.0.subject'));
    }

    public function test_company_user_can_send_message(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Test ticket',
        ]);

        $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson("/api/support/tickets/{$ticket->id}/messages", [
                'body' => 'Follow-up message',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('support_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'Follow-up message',
        ]);
    }

    public function test_company_user_cannot_access_other_company_ticket(): void
    {
        $other = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co-2',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);
        $ticket = SupportTicket::create([
            'company_id' => $other->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Other ticket',
        ]);

        $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson("/api/support/tickets/{$ticket->id}")
            ->assertNotFound();
    }

    // ── Platform-side tests ────────────────────────────────

    public function test_platform_admin_can_list_all_tickets(): void
    {
        SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Ticket A',
        ]);

        $other = Company::create([
            'name' => 'Other Co',
            'slug' => 'other-co-3',
            'plan_key' => 'starter',
            'jobdomain_key' => 'logistique',
        ]);
        SupportTicket::create([
            'company_id' => $other->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Ticket B',
        ]);

        $response = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/support/tickets')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_platform_admin_can_assign_ticket(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Assign test',
        ]);

        $this->actingAs($this->admin, 'platform')
            ->putJson("/api/platform/support/tickets/{$ticket->id}/assign")
            ->assertOk()
            ->assertJsonFragment(['assigned_to_platform_user_id' => $this->admin->id]);
    }

    public function test_platform_admin_can_resolve_ticket(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Resolve test',
        ]);

        $this->actingAs($this->admin, 'platform')
            ->putJson("/api/platform/support/tickets/{$ticket->id}/resolve")
            ->assertOk()
            ->assertJsonFragment(['status' => 'resolved']);

        $this->assertNotNull($ticket->fresh()->resolved_at);
    }

    public function test_platform_admin_can_close_ticket(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Close test',
        ]);

        $this->actingAs($this->admin, 'platform')
            ->putJson("/api/platform/support/tickets/{$ticket->id}/close")
            ->assertOk()
            ->assertJsonFragment(['status' => 'closed']);
    }

    public function test_platform_admin_can_reply_to_ticket(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Reply test',
        ]);

        $this->actingAs($this->admin, 'platform')
            ->postJson("/api/platform/support/tickets/{$ticket->id}/messages", [
                'body' => 'Platform reply',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('support_messages', [
            'ticket_id' => $ticket->id,
            'sender_type' => 'platform_admin',
            'body' => 'Platform reply',
        ]);

        $this->assertNotNull($ticket->fresh()->first_response_at);
    }

    public function test_platform_admin_can_add_internal_note(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Note test',
        ]);

        $this->actingAs($this->admin, 'platform')
            ->postJson("/api/platform/support/tickets/{$ticket->id}/internal-notes", [
                'body' => 'Internal note content',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('support_messages', [
            'ticket_id' => $ticket->id,
            'is_internal' => true,
            'body' => 'Internal note content',
        ]);
    }

    public function test_company_user_cannot_see_internal_notes(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Internal notes test',
        ]);

        // Internal note
        SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'platform_admin',
            'sender_id' => $this->admin->id,
            'body' => 'Secret note',
            'is_internal' => true,
        ]);

        // Public reply
        SupportMessage::create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'platform_admin',
            'sender_id' => $this->admin->id,
            'body' => 'Public reply',
            'is_internal' => false,
        ]);

        $response = $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson("/api/support/tickets/{$ticket->id}/messages")
            ->assertOk();

        $messages = $response->json();
        $this->assertCount(1, $messages);
        $this->assertEquals('Public reply', $messages[0]['body']);
    }

    public function test_platform_metrics_returns_counts(): void
    {
        SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Open ticket',
            'status' => 'open',
        ]);

        SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'In progress ticket',
            'status' => 'in_progress',
            'assigned_to_platform_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'platform')
            ->getJson('/api/platform/support/metrics')
            ->assertOk()
            ->assertJsonFragment(['open' => 1])
            ->assertJsonFragment(['in_progress' => 1]);
    }

    public function test_reply_reopens_waiting_customer_ticket(): void
    {
        $ticket = SupportTicket::create([
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->owner->id,
            'subject' => 'Reopen test',
            'status' => 'waiting_customer',
        ]);

        $this->actingAs($this->owner)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->postJson("/api/support/tickets/{$ticket->id}/messages", [
                'body' => 'My reply',
            ])
            ->assertStatus(201);

        $this->assertEquals('open', $ticket->fresh()->status);
    }
}
