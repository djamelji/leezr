<?php

namespace App\Core\Ai\Adapters;

use App\Core\Ai\Contracts\AiProviderAdapter;
use App\Core\Ai\DTOs\AiCapability;
use App\Core\Ai\DTOs\AiHealthResult;
use App\Core\Ai\DTOs\AiResponse;

/**
 * No-op AI adapter — always returns empty responses with confidence 0.
 * Used when no AI provider is configured.
 *
 * @see \App\Core\Billing\NullPaymentGateway
 */
class NullAiAdapter implements AiProviderAdapter
{
    public function key(): string
    {
        return 'null';
    }

    public function capabilities(): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function healthCheck(): AiHealthResult
    {
        return new AiHealthResult(status: 'healthy', message: 'Null adapter — no AI processing');
    }

    public function complete(string $prompt, array $options = []): AiResponse
    {
        return AiResponse::empty('null');
    }

    public function vision(string $imagePath, string $prompt, array $options = []): AiResponse
    {
        return AiResponse::empty('null');
    }

    public function extractText(string $imagePath, array $options = []): AiResponse
    {
        return AiResponse::empty('null');
    }
}
