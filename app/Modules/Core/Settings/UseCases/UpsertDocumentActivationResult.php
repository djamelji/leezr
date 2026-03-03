<?php

namespace App\Modules\Core\Settings\UseCases;

/**
 * ADR-175: Result of document activation upsert.
 */
final class UpsertDocumentActivationResult
{
    public function __construct(
        public readonly string $code,
        public readonly bool $enabled,
        public readonly bool $requiredOverride,
        public readonly int $order,
    ) {}
}
