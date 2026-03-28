<?php

namespace App\Core\Realtime;

/**
 * Closed registry of allowed realtime topics.
 *
 * ADR-125: No ad hoc topics allowed. Every topic defines
 * which cache keys it invalidates on the frontend.
 *
 * ADR-126: Topics now declare allowed categories, targeting,
 * and protocol version. Existing API preserved for backward compat.
 */
final class TopicRegistry
{
    /**
     * Topic definitions.
     *
     * Each topic declares:
     * - description: human-readable purpose
     * - invalidates: cache keys cleared on the frontend
     * - categories: allowed EventCategory values
     * - targeting: 'company' | 'user' | 'platform'
     * - version: protocol version (default 2)
     */
    public const TOPICS = [
        // ─── Phase 1 topics (ADR-125) ─────────────────────────
        'rbac.changed' => [
            'description' => 'Role permissions or level changed',
            'invalidates' => ['features:nav', 'auth:companies'],
            'categories' => ['invalidation', 'domain', 'audit'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'modules.changed' => [
            'description' => 'Module enabled, disabled, or settings updated',
            'invalidates' => ['features:nav', 'features:modules'],
            'categories' => ['invalidation', 'domain', 'audit'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'plan.changed' => [
            'description' => 'Company plan changed',
            'invalidates' => ['features:nav', 'features:modules', 'auth:companies'],
            'categories' => ['invalidation', 'domain', 'audit'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'jobdomain.changed' => [
            'description' => 'Company jobdomain assigned or changed',
            'invalidates' => ['features:nav', 'features:modules', 'tenant:jobdomain'],
            'categories' => ['invalidation', 'domain', 'audit'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'members.changed' => [
            'description' => 'Member added, removed, or role reassigned',
            'invalidates' => ['auth:companies'],
            'categories' => ['invalidation', 'domain', 'audit'],
            'targeting' => 'company',
            'version' => 2,
        ],

        // ─── Phase 2 topics (ADR-126) — domain events ────────
        'member.joined' => [
            'description' => 'A new member joined the company',
            'invalidates' => ['auth:companies'],
            'categories' => ['domain', 'notification'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'member.removed' => [
            'description' => 'A member was removed from the company',
            'invalidates' => ['auth:companies'],
            'categories' => ['domain', 'notification'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'role.assigned' => [
            'description' => 'A role was assigned to a member',
            'invalidates' => ['features:nav', 'auth:companies'],
            'categories' => ['domain', 'notification'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'module.activated' => [
            'description' => 'A module was activated for the company',
            'invalidates' => ['features:nav', 'features:modules'],
            'categories' => ['domain', 'notification'],
            'targeting' => 'company',
            'version' => 2,
        ],
        'module.deactivated' => [
            'description' => 'A module was deactivated for the company',
            'invalidates' => ['features:nav', 'features:modules'],
            'categories' => ['domain', 'notification'],
            'targeting' => 'company',
            'version' => 2,
        ],

        // ─── ADR-427: Global domain topics ─────────────────────

        // Documents
        'document.updated' => [
            'description' => 'Document uploaded, reviewed, deleted, or status changed',
            'invalidates' => [],
            'categories' => ['domain', 'notification'],
            'targeting' => 'company',
            'version' => 2,
        ],

        // Billing
        'billing.updated' => [
            'description' => 'Subscription, invoice, or payment status changed',
            'invalidates' => [],
            'categories' => ['domain', 'notification'],
            'targeting' => 'company',
            'version' => 2,
        ],

        // Automation
        'automation.updated' => [
            'description' => 'Automation rule executed, enabled, or disabled',
            'invalidates' => [],
            'categories' => ['domain'],
            'targeting' => 'company',
            'version' => 2,
        ],

        // ─── Audit + Security topics ─────────────────────────
        'security.alert' => [
            'description' => 'A security alert was raised',
            'invalidates' => [],
            'categories' => ['security'],
            'targeting' => 'platform',
            'version' => 2,
        ],
        'audit.logged' => [
            'description' => 'An audit event was recorded',
            'invalidates' => [],
            'categories' => ['audit'],
            'targeting' => 'company',
            'version' => 2,
        ],

        // ─── Notification topics ───────────────────────────────
        'notification.created' => [
            'description' => 'A new in-app notification was created for a user',
            'invalidates' => [],
            'categories' => ['notification'],
            'targeting' => 'user',
            'version' => 2,
        ],
    ];

    /**
     * Get the topic definition.
     *
     * @return array|null
     */
    public static function get(string $topic): ?array
    {
        return self::TOPICS[$topic] ?? null;
    }

    /**
     * Get all topic keys.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::TOPICS);
    }

    /**
     * Get the cache keys invalidated by a topic.
     *
     * @return string[]
     */
    public static function invalidates(string $topic): array
    {
        return self::TOPICS[$topic]['invalidates'] ?? [];
    }

    /**
     * Assert that a topic is valid.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(string $topic): void
    {
        if (!isset(self::TOPICS[$topic])) {
            throw new \InvalidArgumentException(
                "[TopicRegistry] Unknown topic '{$topic}'. Allowed: ".implode(', ', self::keys())
            );
        }
    }

    /**
     * Check if a topic exists.
     */
    public static function exists(string $topic): bool
    {
        return isset(self::TOPICS[$topic]);
    }

    /**
     * ADR-126: Assert that a category is valid for a given topic.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidCategory(string $topic, EventCategory $category): void
    {
        self::assertValid($topic);

        $allowed = self::TOPICS[$topic]['categories'] ?? [];

        if (!in_array($category->value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "[TopicRegistry] Category '{$category->value}' not allowed for topic '{$topic}'. Allowed: ".implode(', ', $allowed)
            );
        }
    }

    /**
     * ADR-126: Get allowed categories for a topic.
     *
     * @return string[]
     */
    public static function categories(string $topic): array
    {
        return self::TOPICS[$topic]['categories'] ?? [];
    }

    /**
     * ADR-126: Get the targeting scope for a topic.
     */
    public static function targeting(string $topic): ?string
    {
        return self::TOPICS[$topic]['targeting'] ?? null;
    }
}
