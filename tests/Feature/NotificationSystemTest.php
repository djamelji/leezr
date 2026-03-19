<?php

namespace Tests\Feature;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Modules\AdminModuleService;
use App\Core\Modules\ModuleRegistry;
use App\Core\Notifications\NotificationDispatcher;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\NotificationPreference;
use App\Core\Notifications\NotificationTopic;
use App\Core\Notifications\NotificationTopicRegistry;
use App\Core\Notifications\PlatformNotificationPreference;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformUser;
use App\Platform\RBAC\PlatformPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\ActivatesCompanyModules;
use Tests\TestCase;

/**
 * Notification System — comprehensive tests.
 *
 * Covers:
 *   - NotificationEvent model (create, scopes, markRead)
 *   - NotificationDispatcher (in_app channel, topic deactivation, preference fallback)
 *   - Company notification inbox API (index, unread-count, mark-read, mark-all-read, delete, category filter)
 *   - Company notification preferences API (index, update)
 *   - Platform notification inbox API (index, unread-count, mark-read, mark-all-read, delete)
 *   - Platform notification topic governance (list, update, toggle)
 *   - NotificationPreference model (channelsFor fallback)
 *   - PlatformNotificationPreference model (channelsFor fallback)
 */
class NotificationSystemTest extends TestCase
{
    use RefreshDatabase, ActivatesCompanyModules;

    private User $owner;
    private Company $company;
    private PlatformUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        NotificationTopicRegistry::boot();
        NotificationTopicRegistry::sync();
        PlatformPermissionCatalog::sync();

        // Company + owner
        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'name' => 'Notif Co',
            'slug' => 'notif-co',
            'plan_key' => 'starter',
            'status' => 'active',
            'jobdomain_key' => 'logistique',
        ]);
        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);
        $this->activateCompanyModules($this->company);

        // Platform admin (super_admin)
        $this->platformAdmin = PlatformUser::create([
            'first_name' => 'Admin',
            'last_name' => 'Notif',
            'email' => 'admin-notif@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $superAdmin = PlatformRole::where('key', 'super_admin')->first();
        $this->platformAdmin->roles()->attach($superAdmin);
    }

    private function actAsOwner(): static
    {
        return $this->actingAs($this->owner)->withHeaders(['X-Company-Id' => $this->company->id]);
    }

    private function actAsPlatform(): static
    {
        return $this->actingAs($this->platformAdmin, 'platform');
    }

    private function createNotificationForUser(array $overrides = []): NotificationEvent
    {
        return NotificationEvent::create(array_merge([
            'event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'recipient_type' => 'user',
            'recipient_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'topic_key' => 'billing.payment_received',
            'title' => 'Payment received',
            'body' => 'Your payment of 29.00 EUR was received.',
            'icon' => 'tabler-cash',
            'severity' => 'success',
        ], $overrides));
    }

    private function createNotificationForAdmin(array $overrides = []): NotificationEvent
    {
        return NotificationEvent::create(array_merge([
            'event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'recipient_type' => 'platform_user',
            'recipient_id' => $this->platformAdmin->id,
            'company_id' => null,
            'topic_key' => 'platform.new_subscription',
            'title' => 'New subscription',
            'body' => 'A company subscribed to Pro.',
            'icon' => 'tabler-user-plus',
            'severity' => 'success',
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════
    // 1. NotificationEvent model — creation & scopes
    // ═══════════════════════════════════════════════════════════

    public function test_notification_event_created_with_all_fields(): void
    {
        $event = $this->createNotificationForUser();

        $this->assertDatabaseHas('notification_events', [
            'id' => $event->id,
            'recipient_type' => 'user',
            'recipient_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'topic_key' => 'billing.payment_received',
            'severity' => 'success',
        ]);
        $this->assertNotNull($event->event_uuid);
        $this->assertNull($event->read_at);
    }

    public function test_unread_scope_filters_correctly(): void
    {
        $unread = $this->createNotificationForUser();
        $read = $this->createNotificationForUser(['read_at' => now()]);

        $unreadEvents = NotificationEvent::unread()->get();

        $this->assertTrue($unreadEvents->contains('id', $unread->id));
        $this->assertFalse($unreadEvents->contains('id', $read->id));
    }

    public function test_for_recipient_scope_filters_by_morph(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForAdmin();

        $userEvents = NotificationEvent::forRecipient($this->owner)->get();

        $this->assertCount(1, $userEvents);
        $this->assertEquals('user', $userEvents->first()->recipient_type);
    }

    public function test_mark_read_sets_read_at(): void
    {
        $event = $this->createNotificationForUser();
        $this->assertNull($event->read_at);

        $event->markRead();
        $event->refresh();

        $this->assertNotNull($event->read_at);
    }

    // ═══════════════════════════════════════════════════════════
    // 2. NotificationDispatcher — in_app channel
    // ═══════════════════════════════════════════════════════════

    public function test_dispatcher_creates_in_app_notification(): void
    {
        $count = NotificationDispatcher::send(
            'billing.payment_received',
            [$this->owner],
            ['amount' => '29.00 EUR'],
            $this->company,
        );

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('notification_events', [
            'recipient_type' => 'user',
            'recipient_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'topic_key' => 'billing.payment_received',
        ]);
    }

    public function test_dispatcher_skips_inactive_topic(): void
    {
        $topic = NotificationTopic::find('billing.payment_received');
        $topic->update(['is_active' => false]);

        $count = NotificationDispatcher::send(
            'billing.payment_received',
            [$this->owner],
            ['amount' => '29.00 EUR'],
            $this->company,
        );

        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('notification_events', [
            'topic_key' => 'billing.payment_received',
            'recipient_id' => $this->owner->id,
        ]);
    }

    public function test_dispatcher_respects_user_preference_no_in_app(): void
    {
        // User disables in_app, keeps email only
        NotificationPreference::create([
            'user_id' => $this->owner->id,
            'topic_key' => 'billing.payment_received',
            'channels' => ['email'],
        ]);

        $count = NotificationDispatcher::send(
            'billing.payment_received',
            [$this->owner],
            ['amount' => '29.00 EUR'],
            $this->company,
        );

        // Count only tracks in_app channel, so should be 0
        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('notification_events', [
            'topic_key' => 'billing.payment_received',
            'recipient_id' => $this->owner->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 3. Company notification inbox API
    // ═══════════════════════════════════════════════════════════

    public function test_company_notification_index_returns_paginated(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForUser(['topic_key' => 'billing.invoice_created', 'title' => 'Invoice']);

        $response = $this->actAsOwner()->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'per_page'])
            ->assertJsonCount(2, 'data');
    }

    public function test_company_notification_unread_count(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForUser();
        $this->createNotificationForUser(['read_at' => now()]);

        $response = $this->actAsOwner()->getJson('/api/notifications/unread-count');

        $response->assertOk()
            ->assertJson(['unread_count' => 2]);
    }

    public function test_company_notification_mark_single_read(): void
    {
        $event = $this->createNotificationForUser();

        $response = $this->actAsOwner()->postJson("/api/notifications/{$event->id}/read");

        $response->assertOk()
            ->assertJson(['message' => 'Marked as read.']);

        $this->assertNotNull($event->fresh()->read_at);
    }

    public function test_company_notification_mark_all_read(): void
    {
        $e1 = $this->createNotificationForUser();
        $e2 = $this->createNotificationForUser();

        $response = $this->actAsOwner()->postJson('/api/notifications/read-all');

        $response->assertOk()
            ->assertJson(['message' => 'All notifications marked as read.']);

        $this->assertNotNull($e1->fresh()->read_at);
        $this->assertNotNull($e2->fresh()->read_at);
    }

    public function test_company_notification_delete(): void
    {
        $event = $this->createNotificationForUser();

        $response = $this->actAsOwner()->deleteJson("/api/notifications/{$event->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Notification deleted.']);

        $this->assertDatabaseMissing('notification_events', ['id' => $event->id]);
    }

    public function test_company_notification_filter_unread_only(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForUser(['read_at' => now()]);

        $response = $this->actAsOwner()->getJson('/api/notifications?unread_only=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_company_notification_filter_by_category(): void
    {
        $this->createNotificationForUser(['topic_key' => 'billing.payment_received']);
        $this->createNotificationForUser(['topic_key' => 'members.invited', 'title' => 'Member Invited']);

        $response = $this->actAsOwner()->getJson('/api/notifications?category=billing');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_company_notification_404_on_other_user_notification(): void
    {
        $otherUser = User::factory()->create();
        $event = NotificationEvent::create([
            'event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'recipient_type' => 'user',
            'recipient_id' => $otherUser->id,
            'company_id' => $this->company->id,
            'topic_key' => 'billing.payment_received',
            'title' => 'Other user notification',
        ]);

        $response = $this->actAsOwner()->postJson("/api/notifications/{$event->id}/read");

        $response->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════════
    // 4. Company notification preferences API
    // ═══════════════════════════════════════════════════════════

    public function test_company_preferences_index_returns_topics(): void
    {
        $response = $this->actAsOwner()->getJson('/api/notifications/preferences');

        $response->assertOk()
            ->assertJsonStructure(['preferences']);

        // Should contain at least one company-scoped topic
        $preferences = $response->json('preferences');
        $this->assertNotEmpty($preferences);

        // Each item should have the expected structure
        $first = $preferences[0];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('channels', $first);
        $this->assertArrayHasKey('default_channels', $first);
    }

    public function test_company_preferences_update(): void
    {
        $response = $this->actAsOwner()->putJson('/api/notifications/preferences', [
            'preferences' => [
                [
                    'topic_key' => 'billing.payment_received',
                    'channels' => ['in_app'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Preferences updated.']);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->owner->id,
            'topic_key' => 'billing.payment_received',
        ]);

        $pref = NotificationPreference::where('user_id', $this->owner->id)
            ->where('topic_key', 'billing.payment_received')
            ->first();
        $this->assertEquals(['in_app'], $pref->channels);
    }

    // ═══════════════════════════════════════════════════════════
    // 5. NotificationPreference model — channelsFor fallback
    // ═══════════════════════════════════════════════════════════

    public function test_preference_channels_for_returns_defaults_when_no_preference(): void
    {
        $channels = NotificationPreference::channelsFor($this->owner->id, 'billing.payment_received', ['in_app', 'email']);

        $this->assertEquals(['in_app', 'email'], $channels);
    }

    public function test_preference_channels_for_returns_user_override(): void
    {
        NotificationPreference::create([
            'user_id' => $this->owner->id,
            'topic_key' => 'billing.payment_received',
            'channels' => ['email'],
        ]);

        $channels = NotificationPreference::channelsFor($this->owner->id, 'billing.payment_received', ['in_app', 'email']);

        $this->assertEquals(['email'], $channels);
    }

    // ═══════════════════════════════════════════════════════════
    // 6. Platform notification inbox API
    // ═══════════════════════════════════════════════════════════

    public function test_platform_notification_index(): void
    {
        $this->createNotificationForAdmin();
        $this->createNotificationForAdmin(['topic_key' => 'platform.plan_changed']);

        $response = $this->actAsPlatform()->getJson('/api/platform/me/notifications');

        $response->assertOk()
            ->assertJsonStructure(['data', 'current_page'])
            ->assertJsonCount(2, 'data');
    }

    public function test_platform_notification_unread_count(): void
    {
        $this->createNotificationForAdmin();
        $this->createNotificationForAdmin(['read_at' => now()]);

        $response = $this->actAsPlatform()->getJson('/api/platform/me/notifications/unread-count');

        $response->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    public function test_platform_notification_mark_read(): void
    {
        $event = $this->createNotificationForAdmin();

        $response = $this->actAsPlatform()->postJson("/api/platform/me/notifications/{$event->id}/read");

        $response->assertOk()
            ->assertJson(['message' => 'Marked as read.']);
        $this->assertNotNull($event->fresh()->read_at);
    }

    public function test_platform_notification_mark_all_read(): void
    {
        $e1 = $this->createNotificationForAdmin();
        $e2 = $this->createNotificationForAdmin();

        $response = $this->actAsPlatform()->postJson('/api/platform/me/notifications/read-all');

        $response->assertOk();
        $this->assertNotNull($e1->fresh()->read_at);
        $this->assertNotNull($e2->fresh()->read_at);
    }

    public function test_platform_notification_delete(): void
    {
        $event = $this->createNotificationForAdmin();

        $response = $this->actAsPlatform()->deleteJson("/api/platform/me/notifications/{$event->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('notification_events', ['id' => $event->id]);
    }

    // ═══════════════════════════════════════════════════════════
    // 7. Platform notification topic governance
    // ═══════════════════════════════════════════════════════════

    public function test_platform_topic_list_returns_all_topics(): void
    {
        AdminModuleService::enable('platform.notifications');

        $response = $this->actAsPlatform()->getJson('/api/platform/notifications/topics');

        $response->assertOk()
            ->assertJsonStructure(['topics']);

        $topics = $response->json('topics');
        $this->assertNotEmpty($topics);
        $this->assertArrayHasKey('key', $topics[0]);
        $this->assertArrayHasKey('default_channels', $topics[0]);
        $this->assertArrayHasKey('delivery_count_7d', $topics[0]);
    }

    public function test_platform_topic_toggle_deactivates_and_reactivates(): void
    {
        AdminModuleService::enable('platform.notifications');

        $topic = NotificationTopic::find('billing.payment_received');
        $this->assertTrue($topic->is_active);

        // Toggle off
        $response = $this->actAsPlatform()
            ->putJson('/api/platform/notifications/topics/billing.payment_received/toggle');

        $response->assertOk()
            ->assertJson(['message' => 'Topic deactivated.']);
        $this->assertFalse(NotificationTopic::find('billing.payment_received')->is_active);

        // Toggle back on
        $response = $this->actAsPlatform()
            ->putJson('/api/platform/notifications/topics/billing.payment_received/toggle');

        $response->assertOk()
            ->assertJson(['message' => 'Topic activated.']);
        $this->assertTrue(NotificationTopic::find('billing.payment_received')->is_active);
    }

    public function test_platform_topic_update_changes_metadata(): void
    {
        AdminModuleService::enable('platform.notifications');

        $response = $this->actAsPlatform()
            ->putJson('/api/platform/notifications/topics/billing.payment_received', [
                'severity' => 'warning',
                'default_channels' => ['in_app'],
            ]);

        $response->assertOk();

        $topic = NotificationTopic::find('billing.payment_received');
        $this->assertEquals('warning', $topic->severity);
        $this->assertEquals(['in_app'], $topic->default_channels);
    }

    // ═══════════════════════════════════════════════════════════
    // 8. PlatformNotificationPreference model
    // ═══════════════════════════════════════════════════════════

    public function test_platform_preference_channels_for_falls_back_to_defaults(): void
    {
        $channels = PlatformNotificationPreference::channelsFor(
            $this->platformAdmin->id,
            'platform.new_subscription',
            ['in_app'],
        );

        $this->assertEquals(['in_app'], $channels);
    }

    // ═══════════════════════════════════════════════════════════
    // 9. NotificationTopic model — scopes
    // ═══════════════════════════════════════════════════════════

    public function test_topic_active_scope_excludes_inactive(): void
    {
        $topic = NotificationTopic::find('billing.payment_received');
        $topic->update(['is_active' => false]);

        $activeKeys = NotificationTopic::active()->pluck('key')->toArray();

        $this->assertNotContains('billing.payment_received', $activeKeys);
        // Other topics are still active
        $this->assertContains('billing.invoice_created', $activeKeys);
    }

    public function test_topic_for_scope_returns_company_and_both(): void
    {
        $companyTopics = NotificationTopic::forScope('company')->pluck('key')->toArray();

        // Company-scoped topic
        $this->assertContains('billing.payment_received', $companyTopics);
        // 'both'-scoped topic
        $this->assertContains('security.alert', $companyTopics);
        // Platform-only topic should NOT appear
        $this->assertNotContains('platform.new_subscription', $companyTopics);
    }
}
