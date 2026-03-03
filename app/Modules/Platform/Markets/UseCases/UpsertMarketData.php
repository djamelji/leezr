<?php

namespace App\Modules\Platform\Markets\UseCases;

final class UpsertMarketData
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $key,
        public readonly string $name,
        public readonly string $currency,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly string $dialCode,
        public readonly ?string $flagCode = null,
        public readonly ?string $flagSvg = null,
        public readonly bool $isActive = true,
        public readonly bool $isDefault = false,
        public readonly int $sortOrder = 0,
        public readonly ?array $languageKeys = null,
    ) {}

    public static function fromValidated(?int $id, array $data): self
    {
        return new self(
            id: $id,
            key: $data['key'],
            name: $data['name'],
            currency: $data['currency'],
            locale: $data['locale'],
            timezone: $data['timezone'],
            dialCode: $data['dial_code'],
            flagCode: $data['flag_code'] ?? null,
            flagSvg: $data['flag_svg'] ?? null,
            isActive: $data['is_active'] ?? true,
            isDefault: $data['is_default'] ?? false,
            sortOrder: $data['sort_order'] ?? 0,
            languageKeys: $data['language_keys'] ?? null,
        );
    }
}
