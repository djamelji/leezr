<?php

namespace App\Modules\Core\Settings\UseCases;

/**
 * ADR-180: Result of custom document type creation.
 */
final class CreateCustomDocumentTypeResult
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $label,
        public readonly string $scope,
    ) {}
}
