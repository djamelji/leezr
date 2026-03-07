<?php

namespace App\Core\Billing;

/**
 * ADR-229: Result of a checkout session activation attempt.
 */
class ActivationResult
{
    public function __construct(
        public readonly bool $activated,
        public readonly string $reason = '',
        public readonly bool $idempotent = false,
    ) {}
}
