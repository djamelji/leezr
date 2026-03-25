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

    public function test_company_preferences_index_returns_bundles(): void
    {
        $response = $this->actAsOwner()->getJson('/api/notifications/preferences');

        $response->assertOk()
            ->assertJsonStructure(['bundles', 'available_categories']);

        // Owner sees all bundles (has all permissions)
        $bundles = $response->json('bundles');
        $this->assertNotEmpty($bundles);

        // Each bundle has the expected structure (ADR-382)
        $first = $bundles[0];
        $this->assertArrayHasKey('category', $first);
        $this->assertArrayHasKey('icon', $first);
        $this->assertArrayHasKey('color', $first);
        $this->assertArrayHasKey('in_app', $first);
        $this->assertArrayHasKey('email', $first);
        $this->assertArrayHasKey('locked', $first);
        $this->assertArrayHasKey('topic_count', $first);
    }

    public function test_company_preferences_update_bundles(): void
    {
        $response = $this->actAsOwner()->putJson('/api/notifications/preferences', [
            'bundles' => [
                [
                    'category' => 'billing',
                    'in_app' => true,
                    'email' => false,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Preferences updated.']);

        // All billing topics should have in_app only (email=false)
        $billingTopics = NotificationTopic::active()
            ->forScope('company')
            ->where('category', 'billing')
            ->pluck('key');

        foreach ($billingTopics as $topicKey) {
            $pref = NotificationPreference::where('user_id', $this->owner->id)
                ->where('topic_key', $topicKey)
                ->first();
            $this->assertNotNull($pref, "Missing preference for {$topicKey}");
            $this->assertContains('in_app', $pref->channels);
            $this->assertNotContains('email', $pref->channels);
        }
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

    // ═══════════════════════════════════════════════════════════
    // 10. ADR-382 — Commercial bundles + permission-aware notifications
    // ═══════════════════════════════════════════════════════════

    public function test_category_permissions_mapping_is_complete(): void
    {
        // Every category used in topics should be in CATEGORY_PERMISSIONS
        $topicCategories = NotificationTopic::active()
            ->forScope('company')
            ->distinct()
            ->pluck('category')
            ->toArray();

        foreach ($topicCategories as $category) {
            $this->assertArrayHasKey(
                $category,
                NotificationTopicRegistry::CATEGORY_PERMISSIONS,
                "Category '{$category}' is missing from CATEGORY_PERMISSIONS mapping",
            );
        }
    }

    public function test_preferences_api_returns_bundles_filtered_by_permission(): void
    {
        // Create a member with only members.view (no billing.manage)
        $member = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        // Set up RBAC with a role that only has members.view
        \App\Company\RBAC\CompanyPermissionCatalog::sync();
        $role = \App\Company\RBAC\CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'dispatcher',
            'name' => 'Dispatcher',
            'is_system' => false,
            'is_administrative' => false,
        ]);
        $membersViewPerm = \App\Company\RBAC\CompanyPermission::where('key', 'members.view')->first();
        $supportViewPerm = \App\Company\RBAC\CompanyPermission::where('key', 'support.view')->first();
        $permsToSync = array_filter([$membersViewPerm?->id, $supportViewPerm?->id]);
        $role->permissions()->sync($permsToSync);

        $membership = $member->memberships()->where('company_id', $this->company->id)->first();
        $membership->update(['company_role_id' => $role->id]);

        $response = $this->actingAs($member)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->getJson('/api/notifications/preferences');

        $response->assertOk();

        $categories = collect($response->json('bundles'))->pluck('category')->toArray();

        // Should see members and support (has permission) + security (universal)
        $this->assertContains('security', $categories);
        $this->assertContains('members', $categories);
        // Should NOT see billing (no billing.manage) or modules (no modules.manage)
        $this->assertNotContains('billing', $categories);
        $this->assertNotContains('modules', $categories);
    }

    public function test_preferences_api_owner_sees_all_bundles(): void
    {
        $response = $this->actAsOwner()->getJson('/api/notifications/preferences');

        $response->assertOk();

        $categories = collect($response->json('bundles'))->pluck('category')->toArray();

        // Owner bypasses permissions — should see all company categories
        $this->assertContains('billing', $categories);
        $this->assertContains('members', $categories);
        $this->assertContains('security', $categories);
    }

    public function test_preferences_update_applies_to_all_topics_in_category(): void
    {
        $this->actAsOwner()->putJson('/api/notifications/preferences', [
            'bundles' => [
                ['category' => 'members', 'in_app' => false, 'email' => true],
            ],
        ])->assertOk();

        $memberTopics = NotificationTopic::active()
            ->forScope('company')
            ->where('category', 'members')
            ->pluck('key');

        $this->assertGreaterThan(0, $memberTopics->count());

        foreach ($memberTopics as $topicKey) {
            $pref = NotificationPreference::where('user_id', $this->owner->id)
                ->where('topic_key', $topicKey)
                ->first();
            $this->assertNotNull($pref, "Missing preference for {$topicKey}");
            $this->assertNotContains('in_app', $pref->channels);
            $this->assertContains('email', $pref->channels);
        }
    }

    public function test_preferences_update_respects_locked_category(): void
    {
        // Try to disable in_app for security (locked)
        $this->actAsOwner()->putJson('/api/notifications/preferences', [
            'bundles' => [
                ['category' => 'security', 'in_app' => false, 'email' => false],
            ],
        ])->assertOk();

        $securityTopics = NotificationTopic::active()
            ->forScope('company')
            ->where('category', 'security')
            ->pluck('key');

        foreach ($securityTopics as $topicKey) {
            $pref = NotificationPreference::where('user_id', $this->owner->id)
                ->where('topic_key', $topicKey)
                ->first();
            $this->assertNotNull($pref, "Missing preference for {$topicKey}");
            // in_app should be forced on despite request saying false
            $this->assertContains('in_app', $pref->channels);
        }
    }

    public function test_preferences_update_skips_unauthorized_categories(): void
    {
        // Create a restricted member
        $member = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        \App\Company\RBAC\CompanyPermissionCatalog::sync();
        $role = \App\Company\RBAC\CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'restricted',
            'name' => 'Restricted',
            'is_system' => false,
            'is_administrative' => false,
        ]);
        // Only members.view permission
        $membersViewPerm = \App\Company\RBAC\CompanyPermission::where('key', 'members.view')->first();
        if ($membersViewPerm) {
            $role->permissions()->sync([$membersViewPerm->id]);
        }
        $membership = $member->memberships()->where('company_id', $this->company->id)->first();
        $membership->update(['company_role_id' => $role->id]);

        // Try to update billing prefs (unauthorized)
        $this->actingAs($member)
            ->withHeaders(['X-Company-Id' => $this->company->id])
            ->putJson('/api/notifications/preferences', [
                'bundles' => [
                    ['category' => 'billing', 'in_app' => false, 'email' => false],
                ],
            ])->assertOk(); // Silently skipped, no error

        // No billing preferences should be created
        $billingTopics = NotificationTopic::active()
            ->forScope('company')
            ->where('category', 'billing')
            ->pluck('key');

        foreach ($billingTopics as $topicKey) {
            $this->assertDatabaseMissing('notification_preferences', [
                'user_id' => $member->id,
                'topic_key' => $topicKey,
            ]);
        }
    }

    public function test_inbox_api_returns_available_categories(): void
    {
        $this->createNotificationForUser();

        $response = $this->actAsOwner()->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonStructure(['available_categories']);

        $categories = $response->json('available_categories');
        $this->assertIsArray($categories);
        // Owner should see all categories
        $this->assertContains('billing', $categories);
        $this->assertContains('security', $categories);
    }

    public function test_dispatcher_filters_recipients_by_permission(): void
    {
        // Create a member without billing.manage
        $member = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        \App\Company\RBAC\CompanyPermissionCatalog::sync();
        $role = \App\Company\RBAC\CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'no-billing',
            'name' => 'No Billing',
            'is_system' => false,
            'is_administrative' => false,
        ]);
        // No permissions at all
        $role->permissions()->sync([]);
        $membership = $member->memberships()->where('company_id', $this->company->id)->first();
        $membership->update(['company_role_id' => $role->id]);

        $count = NotificationDispatcher::send(
            'billing.payment_received',
            [$member],
            ['amount' => '29.00 EUR'],
            $this->company,
        );

        // Member lacks billing.manage → filtered out
        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('notification_events', [
            'recipient_id' => $member->id,
            'topic_key' => 'billing.payment_received',
        ]);
    }

    public function test_dispatcher_skips_gate_for_universal_categories(): void
    {
        // Create a member with NO permissions
        $member = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        \App\Company\RBAC\CompanyPermissionCatalog::sync();
        $role = \App\Company\RBAC\CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'basic',
            'name' => 'Basic',
            'is_system' => false,
            'is_administrative' => false,
        ]);
        $role->permissions()->sync([]);
        $membership = $member->memberships()->where('company_id', $this->company->id)->first();
        $membership->update(['company_role_id' => $role->id]);

        // Security is universal (null permission) — should reach everyone
        $count = NotificationDispatcher::send(
            'security.alert',
            [$member],
            ['reason' => 'Suspicious login'],
            $this->company,
        );

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('notification_events', [
            'recipient_id' => $member->id,
            'topic_key' => 'security.alert',
        ]);
    }

    public function test_dispatcher_skips_gate_when_no_company(): void
    {
        // Without company context, permission gate should be skipped
        $count = NotificationDispatcher::send(
            'billing.payment_received',
            [$this->owner],
            ['amount' => '29.00 EUR'],
            null, // no company
        );

        // Should succeed — no company means no permission check
        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('notification_events', [
            'recipient_id' => $this->owner->id,
            'topic_key' => 'billing.payment_received',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 11. ADR-382 — Platform permission-aware notifications
    // ═══════════════════════════════════════════════════════════

    public function test_platform_category_permissions_mapping_is_complete(): void
    {
        $platformCategories = NotificationTopic::active()
            ->forScope('platform')
            ->distinct()
            ->pluck('category')
            ->toArray();

        foreach ($platformCategories as $category) {
            $this->assertArrayHasKey(
                $category,
                NotificationTopicRegistry::PLATFORM_CATEGORY_PERMISSIONS,
                "Platform category '{$category}' is missing from PLATFORM_CATEGORY_PERMISSIONS mapping",
            );
        }
    }

    public function test_platform_preferences_filtered_by_permission(): void
    {
        // Create a restricted platform admin with only manage_support
        $restricted = PlatformUser::create([
            'first_name' => 'Support',
            'last_name' => 'Only',
            'email' => 'support-only@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $role = PlatformRole::create([
            'key' => 'support_only',
            'name' => 'Support Only',
            'is_system' => false,
        ]);
        $supportPerm = \App\Platform\Models\PlatformPermission::where('key', 'manage_support')->first();
        if ($supportPerm) {
            $role->permissions()->sync([$supportPerm->id]);
        }
        $restricted->roles()->attach($role);

        $response = $this->actingAs($restricted, 'platform')
            ->getJson('/api/platform/me/notification-preferences');

        $response->assertOk()
            ->assertJson(['mode' => 'bundles'])
            ->assertJsonStructure(['bundles', 'available_categories']);

        $bundleCategories = collect($response->json('bundles'))->pluck('category')->toArray();
        $availableCategories = $response->json('available_categories');

        // Should see support (has manage_support) + system (universal)
        $this->assertContains('support', $availableCategories);
        $this->assertContains('system', $availableCategories);
        // Should NOT see billing (no view_billing)
        $this->assertNotContains('billing', $availableCategories);
        // Returned bundles should match available categories
        foreach ($bundleCategories as $cat) {
            $this->assertContains($cat, $availableCategories);
        }

        // Verify bundle structure
        $firstBundle = $response->json('bundles.0');
        $this->assertArrayHasKey('category', $firstBundle);
        $this->assertArrayHasKey('icon', $firstBundle);
        $this->assertArrayHasKey('in_app', $firstBundle);
        $this->assertArrayHasKey('email', $firstBundle);
        $this->assertArrayHasKey('locked', $firstBundle);
        $this->assertArrayHasKey('topic_count', $firstBundle);
    }

    public function test_platform_super_admin_gets_granular_mode(): void
    {
        $response = $this->actAsPlatform()
            ->getJson('/api/platform/me/notification-preferences');

        $response->assertOk()
            ->assertJson(['mode' => 'granular'])
            ->assertJsonStructure(['preferences', 'available_categories']);

        $availableCategories = $response->json('available_categories');

        $this->assertContains('billing', $availableCategories);
        $this->assertContains('support', $availableCategories);
        $this->assertContains('system', $availableCategories);

        // Super admin sees per-topic preferences, not bundles
        $preferences = $response->json('preferences');
        $this->assertNotEmpty($preferences);
        $first = $preferences[0];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('channels', $first);
        $this->assertArrayHasKey('category', $first);
    }

    public function test_platform_inbox_returns_available_categories(): void
    {
        $this->createNotificationForAdmin();

        $response = $this->actAsPlatform()
            ->getJson('/api/platform/me/notifications');

        $response->assertOk()
            ->assertJsonStructure(['available_categories']);

        $categories = $response->json('available_categories');
        $this->assertIsArray($categories);
        $this->assertContains('billing', $categories);
    }

    public function test_platform_dispatcher_filters_by_platform_permission(): void
    {
        // Create a restricted platform admin without view_billing
        $restricted = PlatformUser::create([
            'first_name' => 'No',
            'last_name' => 'Billing',
            'email' => 'no-billing-admin@test.com',
            'password' => 'P@ssw0rd!Strong',
        ]);
        $role = PlatformRole::create([
            'key' => 'no_billing_role',
            'name' => 'No Billing',
            'is_system' => false,
        ]);
        $role->permissions()->sync([]); // no permissions
        $restricted->roles()->attach($role);

        $count = NotificationDispatcher::send(
            'platform.new_subscription',
            [$restricted],
            ['company' => 'Test'],
            null,
        );

        // Restricted admin lacks view_billing → filtered out
        $this->assertEquals(0, $count);
        $this->assertDatabaseMissing('notification_events', [
            'recipient_id' => $restricted->id,
            'topic_key' => 'platform.new_subscription',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // ADR-386: Collaborative resolution via entity_key
    // ═══════════════════════════════════════════════════════════

    public function test_send_stores_entity_key(): void
    {
        $entityKey = 'company_document:1:kbis';

        NotificationDispatcher::send(
            'billing.payment_received',
            [$this->owner],
            ['amount' => '29.00'],
            $this->company,
            entityKey: $entityKey,
        );

        $this->assertDatabaseHas('notification_events', [
            'recipient_id' => $this->owner->id,
            'entity_key' => $entityKey,
        ]);
    }

    public function test_send_without_entity_key_stores_null(): void
    {
        NotificationDispatcher::send(
            'billing.payment_received',
            [$this->owner],
            ['amount' => '29.00'],
            $this->company,
        );

        $event = NotificationEvent::where('recipient_id', $this->owner->id)->first();
        $this->assertNull($event->entity_key);
    }

    public function test_resolve_by_entity_marks_all_unread_as_read(): void
    {
        $entityKey = 'company_document:1:kbis';

        // Create 3 notifications with same entity_key for different recipients
        $user2 = \App\Core\Models\User::factory()->create();
        $this->company->memberships()->create(['user_id' => $user2->id, 'role' => 'member']);

        $e1 = $this->createNotificationForUser(['entity_key' => $entityKey]);
        $e2 = $this->createNotificationForUser([
            'entity_key' => $entityKey,
            'recipient_id' => $user2->id,
        ]);
        // One already read — should not be affected
        $e3 = $this->createNotificationForUser([
            'entity_key' => $entityKey,
            'read_at' => now()->subHour(),
        ]);
        // One with different entity_key — should not be affected
        $e4 = $this->createNotificationForUser(['entity_key' => 'member_document:1:2:id_card']);

        $resolved = NotificationDispatcher::resolveByEntity($entityKey);

        $this->assertEquals(2, $resolved);

        // e1 and e2 are now read
        $this->assertNotNull($e1->fresh()->read_at);
        $this->assertNotNull($e2->fresh()->read_at);

        // e3 was already read — read_at unchanged
        $this->assertNotNull($e3->fresh()->read_at);

        // e4 has different entity_key — still unread
        $this->assertNull($e4->fresh()->read_at);
    }

    public function test_resolve_by_entity_returns_zero_when_no_matches(): void
    {
        $resolved = NotificationDispatcher::resolveByEntity('nonexistent:entity:key');
        $this->assertEquals(0, $resolved);
    }

    public function test_entity_key_scope_filters_correctly(): void
    {
        $entityKey = 'member_document:1:5:driving_license';

        $e1 = $this->createNotificationForUser(['entity_key' => $entityKey]);
        $e2 = $this->createNotificationForUser(['entity_key' => 'other:key']);
        $e3 = $this->createNotificationForUser(); // null entity_key

        $results = NotificationEvent::forEntity($entityKey)->get();

        $this->assertCount(1, $results);
        $this->assertEquals($e1->id, $results->first()->id);
    }
}
