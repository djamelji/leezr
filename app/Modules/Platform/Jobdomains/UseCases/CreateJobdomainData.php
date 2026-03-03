<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

final class CreateJobdomainData
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $description = null,
        public readonly array $defaultModules = [],
        public readonly array $defaultFields = [],
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            key: $data['key'],
            label: $data['label'],
            description: $data['description'] ?? null,
            defaultModules: $data['default_modules'] ?? [],
            defaultFields: $data['default_fields'] ?? [],
        );
    }
}
