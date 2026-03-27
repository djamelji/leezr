<?php

namespace App\Core\Ai\DTOs;

final class AiHealthResult
{
    public function __construct(
        public readonly string $status, // healthy, degraded, down
        public readonly ?string $message = null,
        public readonly int $latencyMs = 0,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
        ], fn ($v) => $v !== null && $v !== 0);
    }
}
