<?php

namespace App\Modules\Platform\Jobdomains\UseCases;

final class UpdateJobdomainData
{
    public function __construct(
        public readonly int $id,
        public readonly array $attributes,
    ) {}

    public static function fromValidated(int $id, array $data): self
    {
        return new self(id: $id, attributes: $data);
    }
}
