<?php

namespace Tests\Unit;

use App\Core\Realtime\EventCategory;
use App\Core\Realtime\EventEnvelope;
use App\Core\Realtime\TopicRegistry;
use PHPUnit\Framework\TestCase;

/**
 * ADR-126: Unit tests for EventEnvelope immutable value object.
 */
class EventEnvelopeTest extends TestCase
{
    // ─── Factory methods ─────────────────────────────────

    public function test_invalidation_factory(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 1, ['action' => 'test']);

        $this->assertEquals('rbac.changed', $envelope->topic);
        $this->assertEquals(EventCategory::Invalidation, $envelope->category);
        $this->assertEquals(1, $envelope->companyId);
        $this->assertEquals(['action' => 'test'], $envelope->payload);
        $this->assertNull($envelope->userId);
        $this->assertEquals(2, $envelope->version);
    }

    public function test_domain_factory(): void
    {
        $envelope = EventEnvelope::domain('member.joined', 1, ['user_id' => 5], 99);

        $this->assertEquals(EventCategory::Domain, $envelope->category);
        $this->assertEquals(99, $envelope->userId);
    }

    public function test_notification_factory(): void
    {
        $envelope = EventEnvelope::notification('member.joined', 1, [], 42);

        $this->assertEquals(EventCategory::Notification, $envelope->category);
        $this->assertEquals(42, $envelope->userId);
    }

    public function test_audit_factory(): void
    {
        $envelope = EventEnvelope::audit('audit.logged', 1, ['entry_id' => 'abc']);

        $this->assertEquals(EventCategory::Audit, $envelope->category);
    }

    public function test_security_factory(): void
    {
        $envelope = EventEnvelope::security('security.alert', 1, ['alert_type' => 'suspicious']);

        $this->assertEquals(EventCategory::Security, $envelope->category);
    }

    // ─── ULID ────────────────────────────────────────────

    public function test_id_is_ulid_format(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 1);

        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $envelope->id);
    }

    public function test_two_envelopes_have_different_ids(): void
    {
        $a = EventEnvelope::invalidation('rbac.changed', 1);
        $b = EventEnvelope::invalidation('rbac.changed', 1);

        $this->assertNotEquals($a->id, $b->id);
    }

    public function test_custom_id_is_preserved(): void
    {
        $envelope = new EventEnvelope(
            topic: 'rbac.changed',
            category: EventCategory::Invalidation,
            companyId: 1,
            id: 'CUSTOM_ID_0000000000000000',
        );

        $this->assertEquals('CUSTOM_ID_0000000000000000', $envelope->id);
    }

    // ─── Timestamp ───────────────────────────────────────

    public function test_timestamp_is_auto_generated(): void
    {
        $before = microtime(true);
        $envelope = EventEnvelope::invalidation('rbac.changed', 1);
        $after = microtime(true);

        $this->assertGreaterThanOrEqual($before, $envelope->timestamp);
        $this->assertLessThanOrEqual($after, $envelope->timestamp);
    }

    public function test_custom_timestamp_is_preserved(): void
    {
        $ts = 1700000000.123;
        $envelope = new EventEnvelope(
            topic: 'rbac.changed',
            category: EventCategory::Invalidation,
            companyId: 1,
            timestamp: $ts,
        );

        $this->assertEquals($ts, $envelope->timestamp);
    }

    // ─── Serialization ──────────────────────────────────

    public function test_to_array(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 42, ['action' => 'test']);

        $array = $envelope->toArray();

        $this->assertEquals($envelope->id, $array['id']);
        $this->assertEquals('rbac.changed', $array['topic']);
        $this->assertEquals('invalidation', $array['category']);
        $this->assertEquals(2, $array['version']);
        $this->assertEquals(42, $array['company_id']);
        $this->assertNull($array['user_id']);
        $this->assertEquals(['action' => 'test'], $array['payload']);
        $this->assertIsArray($array['invalidates']);
        $this->assertGreaterThan(0, $array['timestamp']);
    }

    public function test_to_json(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 42);

        $json = $envelope->toJson();
        $decoded = json_decode($json, true);

        $this->assertEquals('rbac.changed', $decoded['topic']);
        $this->assertEquals(42, $decoded['company_id']);
        $this->assertEquals('invalidation', $decoded['category']);
    }

    public function test_invalidates_are_resolved_from_topic_registry(): void
    {
        $envelope = EventEnvelope::invalidation('rbac.changed', 1);

        $array = $envelope->toArray();

        $this->assertEquals(
            TopicRegistry::invalidates('rbac.changed'),
            $array['invalidates'],
        );
    }

    // ─── Validation ─────────────────────────────────────

    public function test_invalid_topic_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown topic');

        EventEnvelope::invalidation('nonexistent.topic', 1);
    }

    public function test_invalid_category_for_topic_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed');

        // member.joined does not allow invalidation
        new EventEnvelope(
            topic: 'member.joined',
            category: EventCategory::Invalidation,
            companyId: 1,
        );
    }

    // ─── EventCategory ──────────────────────────────────

    public function test_invalidation_sse_event_type_is_backward_compat(): void
    {
        $this->assertEquals('invalidate', EventCategory::Invalidation->sseEventType());
    }

    public function test_other_categories_use_lowercase_name(): void
    {
        $this->assertEquals('domain', EventCategory::Domain->sseEventType());
        $this->assertEquals('notification', EventCategory::Notification->sseEventType());
        $this->assertEquals('audit', EventCategory::Audit->sseEventType());
        $this->assertEquals('security', EventCategory::Security->sseEventType());
    }
}
