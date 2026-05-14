<?php

namespace App\Core\Workforce\Dsn\Gateway\NetEntreprises;

/**
 * DTO returned by NetEntreprisesClientInterface::poll().
 *
 * Represents the asynchronous CCO/BAN/CRM response from
 * Net-Entreprises after polling for declaration status.
 *
 * Sprint 7.2 — ADR-530
 */
readonly class NetEntreprisesPollResponse
{
    public function __construct(
        public string $gatewayStatus,
        public bool $terminal,
        public ?string $receipt = null,
        public ?string $report = null,
        public array $metadata = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawResponse = [],
    ) {}

    /**
     * EN_COURS — still processing.
     */
    public static function pending(array $rawResponse = []): self
    {
        return new self(
            gatewayStatus: 'pending',
            terminal: false,
            rawResponse: $rawResponse,
        );
    }

    /**
     * CCO received — conformity certificate (business acceptance).
     */
    public static function accepted(?string $report = null, array $metadata = [], array $rawResponse = []): self
    {
        return new self(
            gatewayStatus: 'cco_accepted',
            terminal: true,
            report: $report,
            metadata: $metadata,
            rawResponse: $rawResponse,
        );
    }

    /**
     * BAN received — anomaly report (business rejection).
     */
    public static function rejected(
        string $errorCode,
        string $errorMessage,
        ?string $report = null,
        array $rawResponse = [],
    ): self {
        return new self(
            gatewayStatus: 'ban_rejected',
            terminal: true,
            report: $report,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
        );
    }
}
