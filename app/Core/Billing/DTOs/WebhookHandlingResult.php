<?php

namespace App\Core\Billing\DTOs;

final class WebhookHandlingResult
{
    public function __construct(
        public readonly bool $handled,
        public readonly ?string $action = null,
        public readonly ?string $error = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'handled' => $this->handled,
            'action' => $this->action,
            'error' => $this->error,
        ], fn ($v) => $v !== null);
    }
}
