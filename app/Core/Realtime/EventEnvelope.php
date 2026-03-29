<?php

namespace App\Core\Realtime;

use Illuminate\Support\Str;

/**
 * ADR-126: Immutable event envelope — replaces RealtimeEvent.
 *
 * Every realtime event is wrapped in an envelope carrying:
 * - ULID for deduplication and ordering
 * - Category for channel routing (invalidation, domain, audit, etc.)
 * - Version for protocol evolution
 * - Optional userId for user-targeted events
 * - Invalidation keys resolved from TopicRegistry
 */
class EventEnvelope
{
    public readonly string $id;
    public readonly float $timestamp;

    public function __construct(
        public readonly string $topic,
        public readonly EventCategory $category,
        public readonly ?int $companyId,
        public readonly array $payload = [],
        public readonly ?int $userId = null,
        public readonly int $version = 2,
        ?string $id = null,
        ?float $timestamp = null,
    ) {
        $this->id = $id ?? (string) Str::ulid();
        $this->timestamp = $timestamp ?? microtime(true);

        TopicRegistry::assertValid($this->topic);
        TopicRegistry::assertValidCategory($this->topic, $this->category);
    }

    // ─── Static factories ────────────────────────────────────

    public static function invalidation(string $topic, ?int $companyId, array $payload = []): self
    {
        return new self($topic, EventCategory::Invalidation, $companyId, $payload);
    }

    public static function domain(string $topic, ?int $companyId, array $payload = [], ?int $userId = null): self
    {
        return new self($topic, EventCategory::Domain, $companyId, $payload, $userId);
    }

    public static function notification(string $topic, ?int $companyId, array $payload = [], ?int $userId = null): self
    {
        return new self($topic, EventCategory::Notification, $companyId, $payload, $userId);
    }

    public static function audit(string $topic, ?int $companyId, array $payload = [], ?int $userId = null): self
    {
        return new self($topic, EventCategory::Audit, $companyId, $payload, $userId);
    }

    public static function security(string $topic, ?int $companyId, array $payload = [], ?int $userId = null): self
    {
        return new self($topic, EventCategory::Security, $companyId, $payload, $userId);
    }

    // ─── Serialization ──────────────────────────────────────

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'category' => $this->category->value,
            'version' => $this->version,
            'company_id' => $this->companyId,
            'user_id' => $this->userId,
            'payload' => $this->payload,
            'invalidates' => TopicRegistry::invalidates($this->topic),
            'timestamp' => $this->timestamp,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
