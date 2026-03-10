<?php

namespace App\Console\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait HasCorrelationId
{
    protected string $correlationId;

    protected function initCorrelationId(): void
    {
        $this->correlationId = (string) Str::uuid();

        Log::withContext(['correlation_id' => $this->correlationId]);
    }
}
