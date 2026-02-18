<?php

namespace App\Core\Typography;

/**
 * Immutable value object representing the typography configuration
 * stored in platform_settings.typography JSON column.
 */
final class TypographyPayload
{
    public function __construct(
        public readonly string $activeSource,
        public readonly ?int $activeFamilyId,
        public readonly bool $googleFontsEnabled,
        public readonly ?string $googleActiveFamily,
        public readonly array $googleWeights,
        public readonly ?int $headingsFamilyId,
        public readonly ?int $bodyFamilyId,
    ) {}

    public static function defaults(): self
    {
        return new self(
            activeSource: 'local',
            activeFamilyId: null,
            googleFontsEnabled: false,
            googleActiveFamily: null,
            googleWeights: [300, 400, 500, 600, 700],
            headingsFamilyId: null,
            bodyFamilyId: null,
        );
    }

    public function toArray(): array
    {
        return [
            'active_source' => $this->activeSource,
            'active_family_id' => $this->activeFamilyId,
            'google_fonts_enabled' => $this->googleFontsEnabled,
            'google_active_family' => $this->googleActiveFamily,
            'google_weights' => $this->googleWeights,
            'headings_family_id' => $this->headingsFamilyId,
            'body_family_id' => $this->bodyFamilyId,
        ];
    }
}
