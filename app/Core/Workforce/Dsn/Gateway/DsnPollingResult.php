<?php

namespace App\Core\Workforce\Dsn\Gateway;

/**
 * Result of a DSN gateway polling attempt.
 *
 * Carries the external gateway status and any available receipts/reports.
 *
 * Sprint 7.1 — ADR-529
 */
readonly class DsnPollingResult
{
    /**
     * @param  string  $gatewayStatus  External status: aee_received, are_rejected, cco_accepted, ban_rejected, pending, poll_timeout
     * @param  bool  $terminal  Whether this status is final (no more polling needed)
     * @param  string|null  $technicalReceiptPath  Path to stored AEE/ARE file
     * @param  string|null  $businessReportPath  Path to stored CCO/BAN file
     * @param  array  $metadata  CRM and other gateway-specific metadata
     * @param  string|null  $errorCode  Error code if rejection
     * @param  string|null  $errorMessage  Human-readable error if rejection
     * @param  array  $rawResponse  Raw gateway response (secrets filtered)
     */
    public function __construct(
        public string $gatewayStatus,
        public bool $terminal,
        public ?string $technicalReceiptPath = null,
        public ?string $businessReportPath = null,
        public array $metadata = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawResponse = [],
    ) {}

    public static function pending(array $rawResponse = []): self
    {
        return new self(
            gatewayStatus: 'pending',
            terminal: false,
            rawResponse: $rawResponse,
        );
    }

    public static function accepted(
        ?string $businessReportPath = null,
        array $metadata = [],
        array $rawResponse = [],
    ): self {
        return new self(
            gatewayStatus: 'cco_accepted',
            terminal: true,
            businessReportPath: $businessReportPath,
            metadata: $metadata,
            rawResponse: $rawResponse,
        );
    }

    public static function rejected(
        string $errorCode,
        string $errorMessage,
        ?string $businessReportPath = null,
        array $rawResponse = [],
    ): self {
        return new self(
            gatewayStatus: 'ban_rejected',
            terminal: true,
            businessReportPath: $businessReportPath,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
        );
    }

    public static function timeout(): self
    {
        return new self(
            gatewayStatus: 'poll_timeout',
            terminal: true,
            errorCode: 'polling_timeout',
            errorMessage: 'No conclusive response after maximum polling duration.',
        );
    }
}
