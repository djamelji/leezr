<?php

namespace App\Core\Workforce\Dsn\Gateway;

/**
 * Result of a DSN gateway submission attempt.
 *
 * Sprint 6.5 — ADR-523
 */
readonly class DsnSubmissionResult
{
    public function __construct(
        public bool $success,
        public ?string $reference,
        public ?string $error = null,
        public array $rawResponse = [],
    ) {}

    public static function success(string $reference, array $rawResponse = []): self
    {
        return new self(
            success: true,
            reference: $reference,
            rawResponse: $rawResponse,
        );
    }

    public static function failure(string $error, array $rawResponse = []): self
    {
        return new self(
            success: false,
            reference: null,
            error: $error,
            rawResponse: $rawResponse,
        );
    }
}
