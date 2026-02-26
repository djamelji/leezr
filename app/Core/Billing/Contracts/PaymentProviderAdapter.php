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
}
