<?php

namespace App\Core\Settings;

use App\Platform\Models\PlatformSetting;

/**
 * Immutable value object representing the maintenance mode configuration.
 * Stored in platform_settings.maintenance JSON column.
 */
final class MaintenanceSettingsPayload
{
    public function __construct(
        public readonly bool $enabled,
        public readonly array $allowlistIps,
        public readonly string $headline,
        public readonly ?string $subheadline,
        public readonly ?string $description,
        public readonly string $ctaText,
        public readonly string $listSlug,
    ) {}

    public static function defaults(): self
    {
        return new self(
            enabled: false,
            allowlistIps: [],
            headline: "We'll be back soon!",
            subheadline: "We're working hard to improve the experience.",
            description: 'Our website is currently undergoing scheduled maintenance.',
            ctaText: 'Notify Me',
            listSlug: 'maintenance',
        );
    }

    /**
     * Build from platform_settings DB row, merging over defaults.
     */
    public static function fromSettings(): self
    {
        $db = PlatformSetting::instance()->maintenance ?? [];
        $d = self::defaults();

        return new self(
            enabled: $db['enabled'] ?? $d->enabled,
            allowlistIps: $db['allowlist_ips'] ?? $d->allowlistIps,
            headline: $db['headline'] ?? $d->headline,
            subheadline: $db['subheadline'] ?? $d->subheadline,
            description: $db['description'] ?? $d->description,
            ctaText: $db['cta_text'] ?? $d->ctaText,
            listSlug: $db['list_slug'] ?? $d->listSlug,
        );
    }

    /**
     * Snake_case array for DB storage.
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'allowlist_ips' => $this->allowlistIps,
            'headline' => $this->headline,
            'subheadline' => $this->subheadline,
            'description' => $this->description,
            'cta_text' => $this->ctaText,
            'list_slug' => $this->listSlug,
        ];
    }
}
