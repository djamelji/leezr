<?php

namespace App\Core\Billing\DTOs;

final class HealthResult
{
    public function __construct(
        public readonly string $status, // healthy, degraded, down
        public readonly ?string $message = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message,
        ], fn ($v) => $v !== null);
    }
}
