<?php

namespace App\Core\Workforce\Dsn;

/**
 * Result of a DSN pre-flight check before submission.
 *
 * Immutable DTO — use static factories.
 *
 * Sprint 6.8 — ADR-526
 */
readonly class PreflightResult
{
    public function __construct(
        public bool $isReady,
        public array $blockingErrors,
        public array $warnings,
    ) {}

    public static function ready(array $warnings = []): self
    {
        return new self(isReady: true, blockingErrors: [], warnings: $warnings);
    }

    public static function blocked(array $blockingErrors, array $warnings = []): self
    {
        return new self(isReady: false, blockingErrors: $blockingErrors, warnings: $warnings);
    }

    public function toArray(): array
    {
        return [
            'is_ready' => $this->isReady,
            'blocking_error_count' => count($this->blockingErrors),
            'warning_count' => count($this->warnings),
            'blocking_errors' => $this->blockingErrors,
            'warnings' => $this->warnings,
        ];
    }
}
