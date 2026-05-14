<?php

namespace App\Core\Workforce\Dsn\Gateway\NetEntreprises;

/**
 * DTO returned by NetEntreprisesClientInterface::submit().
 *
 * Represents the synchronous AEE/ARE response from Net-Entreprises
 * after a DSN file deposit.
 *
 * Sprint 7.2 — ADR-530
 */
readonly class NetEntreprisesSubmitResponse
{
    public function __construct(
        public bool $success,
        public ?string $reference = null,
        public ?string $receipt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $retryable = false,
        public array $rawResponse = [],
    ) {}

    /**
     * AEE received — technical acceptance.
     */
    public static function accepted(string $reference, ?string $receipt = null, array $rawResponse = []): self
    {
        return new self(
            success: true,
            reference: $reference,
            receipt: $receipt,
            rawResponse: $rawResponse,
        );
    }

    /**
     * ARE received — technical rejection.
     */
    public static function rejected(string $errorCode, string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            retryable: false,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Transport/network error — retryable.
     */
    public static function transportError(string $errorMessage, array $rawResponse = []): self
    {
        return new self(
            success: false,
            errorCode: 'transport_error',
            errorMessage: $errorMessage,
            retryable: true,
            rawResponse: $rawResponse,
        );
    }
}
