<?php

namespace App\Modules\Platform\Markets\UseCases;

final class UpsertLegalStatusData
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?string $marketKey,
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $isVatApplicable,
        public readonly ?float $vatRate,
        public readonly bool $isDefault = false,
        public readonly int $sortOrder = 0,
    ) {}

    public static function fromValidated(?int $id, ?string $marketKey, array $data): self
    {
        return new self(
            id: $id,
            marketKey: $marketKey,
            key: $data['key'],
            name: $data['name'],
            description: $data['description'] ?? null,
            isVatApplicable: $data['is_vat_applicable'],
            vatRate: $data['vat_rate'] ?? null,
            isDefault: $data['is_default'] ?? false,
            sortOrder: $data['sort_order'] ?? 0,
        );
    }
}
