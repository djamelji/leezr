<?php

namespace App\Core\Ai\Contracts;

use App\Core\Ai\DTOs\AiCapability;
use App\Core\Ai\DTOs\AiHealthResult;
use App\Core\Ai\DTOs\AiResponse;

/**
 * AI provider adapter — neutral primitives only, zero business logic.
 *
 * Mirrors PaymentProviderAdapter: generic interface, specific adapters.
 * Business logic lives in module services (e.g. DocumentAiAnalysisService).
 */
interface AiProviderAdapter
{
    public function key(): string;

    /**
     * @return AiCapability[]
     */
    public function capabilities(): array;

    public function isAvailable(): bool;

    public function healthCheck(): AiHealthResult;

    /**
     * Text completion (chat/generation).
     */
    public function complete(string $prompt, array $options = []): AiResponse;

    /**
     * Vision: analyze an image with a prompt.
     */
    public function vision(string $imagePath, string $prompt, array $options = []): AiResponse;

    /**
     * Extract text from an image (OCR-style).
     */
    public function extractText(string $imagePath, array $options = []): AiResponse;
}
