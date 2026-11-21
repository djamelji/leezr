<?php

namespace App\Modules\Workforce\Http\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * ADR-848 (Leezr 4.2 M9) — the response every Workforce controller repeated around its use cases : the result as
 * JSON, or a business refusal (DomainException) as 422 with its message. One implementation, same contract.
 */
trait RespondsToDomainRefusals
{
    protected function domain(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json($fn(), $status);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
