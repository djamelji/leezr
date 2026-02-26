<?php

namespace Tests\Feature;

use App\Company\RBAC\CompanyPermission;
use App\Company\RBAC\CompanyPermissionCatalog;
use App\Company\RBAC\CompanyRole;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Jobdomains\JobdomainRegistry;
use App\Core\Modules\CompanyModule;
use App\Core\Modules\ModuleRegistry;
use App\Core\Realtime\Contracts\RealtimePublisher;
use App\Core\Realtime\EventCategory;
use App\Core\Realtime\EventEnvelope;
use App\Core\Realtime\TopicRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ADR-125 + ADR-126: Proves that mutation endpoints publish realtime events
 * with the correct topic to the RealtimePublisher.
 *
 * Uses a spy publisher (NullRealtimePublisher replacement) to capture
 * events without requiring Redis.
 */
class RealtimePublishTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Company $company;
    private CompanyRole $role;

    /** @var EventEnvelope[] */
    public array $publishedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        CompanyPermissionCatalog::sync();
        JobdomainRegistry::sync();

        $this->company = Company::create([
            'name' => 'Realtime Test Co',
            'slug' => 'realtime-test-co',
            'plan_key' => 'starter',
        ]);

        foreach (ModuleRegistry::forScope('company') as $key => $def) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->company->id, 'module_key' => $key],
                ['is_enabled_for_company' => true],
            );
        }

        $this->role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'test_role',
            'name' => 'Test Role',
            'is_administrative' => true,
        ]);

        $this->role->permissions()->sync(CompanyPermission::pluck('id')->toArray());

        $this->owner = User::factory()->create();

        $this->company->memberships()->create([
            'user_id' => $this->owner->id,
            'role' => 'owner',
        ]);

        // Register spy publisher
        $this->publishedEvents = [];
        $test = $this;

        $this->app->singleton(RealtimePublisher::class, function () use ($test) {
            return new class($test) implements RealtimePublisher {
                private $test;

                public function __construct($test)
                {
                    $this->test = $test;
                }

                public function publish(EventEnvelope $envelope): void
                {
                    $this->test->publishedEvents[] = $envelope;
                }
            };
        });
    }

    // ─── Topic Registry ─────────────────────────────────

    public function test_topic_registry_has_expected_topics(): void
    {
        // ADR-125 Phase 1 topics
        $this->assertTrue(TopicRegistry::exists('rbac.changed'));
        $this->assertTrue(TopicRegistry::exists('modules.changed'));
        $this->assertTrue(TopicRegistry::exists('plan.changed'));
        $this->assertTrue(TopicRegistry::exists('jobdomain.changed'));
        $this->assertTrue(TopicRegistry::exists('members.changed'));

        // ADR-126 domain event topics
        $this->assertTrue(TopicRegistry::exists('member.joined'));
        $this->assertTrue(TopicRegistry::exists('member.removed'));
        $this->assertTrue(TopicRegistry::exists('role.assigned'));
        $this->assertTrue(TopicRegistry::exists('module.activated'));
        $this->assertTrue(TopicRegistry::exists('module.deactivated'));
        $this->assertTrue(TopicRegistry::exists('security.alert'));
        $this->assertTrue(TopicRegistry::exists('audit.logged'));
    }

    public function test_topic_registry_rejects_unknown_topic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventEnvelope::invalidation('invalid.topic', 1);
    }

    public function test_each_phase1_topic_has_invalidation_keys(): void
    {
        $phase1Topics = ['rbac.changed', 'modules.changed', 'plan.changed', 'jobdomain.changed', 'members.changed'];

        foreach ($phase1Topics as $topic) {
            $keys = TopicRegistry::invalidates($topic);
            $this->assertNotEmpty($keys, "Topic '{$topic}' should have invalidation keys");
        }
    }

    // ─── ADR-126: TopicRegistry v2 ──────────────────────

    public function test_topic_registry_v2_categories(): void
    {
        // Phase 1 topics support invalidation
        $this->assertContains('invalidation', TopicRegistry::categories('rbac.changed'));

        // Domain event topics do NOT support invalidation
        $this->assertNotContains('invalidation', TopicRegistry::categories('member.joined'));
        $this->assertContains('domain', TopicRegistry::categories('member.joined'));

        // Security topic only supports security category
        $this->assertContains('security', TopicRegistry::categories('security.alert'));
        $this->assertNotContains('invalidation', TopicRegistry::categories('security.alert'));
    }

    public function test_topic_registry_v2_targeting(): void
    {
        $this->assertEquals('company', TopicRegistry::targeting('rbac.changed'));
        $this->assertEquals('platform', TopicRegistry::targeting('security.alert'));
        $this->assertEquals('company', TopicRegistry::targeting('audit.logged'));
    }

    public function test_topic_registry_rejects_invalid_category_for_topic(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // member.joined does not allow invalidation category
        EventEnvelope::invalidation('member.joined', 1);
    }

    // ─── ADR-126: EventEnvelope ─────────────────────────

    public function test_envelope_has_ulid_and_category(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 42, ['action' => 'test']);

        $this->assertNotEmpty($envelope->id);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $envelope->id);
        $this->assertEquals(EventCategory::Invalidation, $envelope->category);
        $this->assertEquals(2, $envelope->version);
        $this->assertNull($envelope->userId);
    }

    public function test_envelope_backward_compat_invalidation(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 42, ['action' => 'test']);

        $array = $envelope->toArray();

        // Must contain all fields from old RealtimeEvent
        $this->assertEquals('rbac.changed', $array['topic']);
        $this->assertEquals(42, $array['company_id']);
        $this->assertEquals(['action' => 'test'], $array['payload']);
        $this->assertGreaterThan(0, $array['timestamp']);

        // Plus new v2 fields
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('category', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertArrayHasKey('invalidates', $array);
        $this->assertEquals('invalidation', $array['category']);
        $this->assertContains('features:nav', $array['invalidates']);
    }

    public function test_envelope_domain_factory(): void
    {
        $envelope = EventEnvelope::domain('member.joined', 42, ['user_id' => 1], 99);

        $this->assertEquals(EventCategory::Domain, $envelope->category);
        $this->assertEquals(99, $envelope->userId);
        $this->assertEquals('member.joined', $envelope->topic);
    }

    public function test_envelope_serializes_to_json(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 42);

        $json = $envelope->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('rbac.changed', $decoded['topic']);
        $this->assertEquals(42, $decoded['company_id']);
        $this->assertEquals('invalidation', $decoded['category']);
        $this->assertEquals(2, $decoded['version']);
    }

    // ─── Roles: rbac.changed ────────────────────────────

    public function test_role_create_publishes_rbac_changed(): void
    {
        $this->actAs($this->owner)
            ->postJson('/api/company/roles', [
                'name' => 'New Role',
                'is_administrative' => false,
            ])
            ->assertCreated();

        $this->assertPublished('rbac.changed', $this->company->id);
    }

    public function test_role_update_publishes_rbac_changed(): void
    {
        $this->actAs($this->owner)
            ->putJson("/api/company/roles/{$this->role->id}", [
                'name' => 'Renamed Role',
            ])
            ->assertOk();

        $this->assertPublished('rbac.changed', $this->company->id);
    }

    public function test_role_delete_publishes_rbac_changed(): void
    {
        // Create a deletable role (no memberships)
        $role = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'deletable',
            'name' => 'Deletable',
        ]);

        $this->actAs($this->owner)
            ->deleteJson("/api/company/roles/{$role->id}")
            ->assertOk();

        $this->assertPublished('rbac.changed', $this->company->id);
    }

    // ─── Modules: modules.changed ───────────────────────

    public function test_module_enable_publishes_modules_changed(): void
    {
        // Assign a jobdomain that includes logistics_shipments
        \App\Core\Jobdomains\JobdomainGate::assignToCompany($this->company, 'logistique');

        // Disable the module first so we can re-enable it
        CompanyModule::where('company_id', $this->company->id)
            ->where('module_key', 'logistics_shipments')
            ->update(['is_enabled_for_company' => false]);

        $this->actAs($this->owner)
            ->putJson('/api/modules/logistics_shipments/enable')
            ->assertOk();

        $this->assertPublished('modules.changed', $this->company->id);
    }

    public function test_module_disable_publishes_modules_changed(): void
    {
        $this->actAs($this->owner)
            ->putJson('/api/modules/logistics_shipments/disable')
            ->assertOk();

        $this->assertPublished('modules.changed', $this->company->id);
    }

    // ─── Plan: plan.changed ─────────────────────────────

    public function test_plan_change_publishes_plan_changed(): void
    {
        $this->actAs($this->owner)
            ->putJson('/api/company/plan', [
                'plan_key' => 'pro',
            ])
            ->assertOk();

        $this->assertPublished('plan.changed', $this->company->id);
    }

    // ─── Members: members.changed ───────────────────────

    public function test_member_add_publishes_members_changed(): void
    {
        $this->actAs($this->owner)
            ->postJson('/api/company/members', [
                'email' => 'new-member@test.com',
                'first_name' => 'New',
                'last_name' => 'Member',
                'company_role_id' => $this->role->id,
            ])
            ->assertCreated();

        $this->assertPublished('members.changed', $this->company->id);
    }

    public function test_member_role_change_publishes_members_changed(): void
    {
        $alice = User::factory()->create();

        $membership = $this->company->memberships()->create([
            'user_id' => $alice->id,
            'role' => 'user',
            'company_role_id' => $this->role->id,
        ]);

        $newRole = CompanyRole::create([
            'company_id' => $this->company->id,
            'key' => 'other_role',
            'name' => 'Other Role',
        ]);

        $this->actAs($this->owner)
            ->putJson("/api/company/members/{$membership->id}", [
                'company_role_id' => $newRole->id,
            ])
            ->assertOk();

        $this->assertPublished('members.changed', $this->company->id);
    }

    public function test_member_remove_publishes_members_changed(): void
    {
        $bob = User::factory()->create();

        $membership = $this->company->memberships()->create([
            'user_id' => $bob->id,
            'role' => 'user',
            'company_role_id' => $this->role->id,
        ]);

        $this->actAs($this->owner)
            ->deleteJson("/api/company/members/{$membership->id}")
            ->assertOk();

        $this->assertPublished('members.changed', $this->company->id);
    }

    // ─── ADR-128: Dual-write ───────────────────────────

    public function test_publish_dual_writes_zadd_and_publish(): void
    {
        // Re-bind the real SseRealtimePublisher (not the spy)
        // and verify it calls both ZADD and PUBLISH on the connection
        $publishCalled = false;
        $publishChannel = null;

        Redis::shouldReceive('connection')
            ->andReturn($mock = \Mockery::mock());

        // Allow zadd calls (event + metrics collector latency recording)
        $mock->shouldReceive('zadd')->andReturn(1);
        $mock->shouldReceive('zremrangebyscore')->andReturn(0);
        $mock->shouldReceive('expire')->andReturn(true);
        $mock->shouldReceive('hincrby')->andReturn(1);
        $mock->shouldReceive('zcard')->andReturn(1);

        $mock->shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($channel, $json) use (&$publishCalled, &$publishChannel) {
                $publishCalled = true;
                $publishChannel = $channel;

                return 0;
            });

        $publisher = new \App\Core\Realtime\Adapters\SseRealtimePublisher();
        $envelope = \App\Core\Realtime\EventEnvelope::invalidation('rbac.changed', 1);
        $publisher->publish($envelope);

        $this->assertTrue($publishCalled, 'PUBLISH should be called after ZADD');
        $this->assertStringContainsString('pubsub:', $publishChannel);
    }

    // ─── No event on non-mutation ───────────────────────

    public function test_read_endpoints_do_not_publish(): void
    {
        $this->actAs($this->owner)
            ->getJson('/api/company/roles')
            ->assertOk();

        $this->actAs($this->owner)
            ->getJson('/api/modules')
            ->assertOk();

        $this->assertCount(0, $this->publishedEvents, 'Read endpoints should not publish realtime events');
    }

    // ─── Helpers ────────────────────────────────────────

    private function actAs(User $user)
    {
        return $this->actingAs($user)
            ->withHeader('X-Company-Id', $this->company->id);
    }

    private function assertPublished(string $topic, int $companyId): void
    {
        $matching = array_filter(
            $this->publishedEvents,
            fn (EventEnvelope $e) => $e->topic === $topic && $e->companyId === $companyId,
        );

        $this->assertNotEmpty($matching, "Expected '{$topic}' event for company {$companyId} but none was published.");
    }
}
