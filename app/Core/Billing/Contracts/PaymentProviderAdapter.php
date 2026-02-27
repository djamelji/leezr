<?php

namespace App\Core\Billing\Contracts;

use App\Core\Billing\DTOs\HealthResult;
use App\Core\Billing\DTOs\WebhookHandlingResult;

/**
 * Extended payment provider interface.
 * Adds health checks, method discovery, and idempotent webhook handling.
 */
interface PaymentProviderAdapter extends PaymentGatewayProvider
{
    /**
     * Which payment methods this adapter currently supports.
     *
     * @return string[] e.g. ['card', 'sepa_debit']
     */
    public function availableMethods(): array;

    /**
     * Health check — verifies credentials and connectivity.
     */
    public function healthCheck(): HealthResult;

    /**
     * Handle webhook with provider-specific verification.
     */
    public function handleWebhookEvent(array $payload, array $headers): WebhookHandlingResult;

    /**
     * Issue a refund via the payment provider.
     *
     * @param string $providerPaymentId External payment/charge ID
     * @param int $amount Amount in cents
     * @param array $metadata Additional metadata
     * @return array{provider_refund_id: string, amount: int, status: string, raw_response: array}
     */
    public function refund(string $providerPaymentId, int $amount, array $metadata = []): array;

    /**
     * Verify webhook signature for this provider.
     * Throws on failure. No-op for providers without signature verification.
     *
     * @throws \RuntimeException If signature is invalid
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): void;
}
