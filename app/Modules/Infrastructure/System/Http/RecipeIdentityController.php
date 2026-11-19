<?php

namespace App\Modules\Infrastructure\System\Http;

use App\Console\Recipe\RecipeDatabaseGuard;
use Illuminate\Http\JsonResponse;

/**
 * ADR-831 — proof, given by the SERVED application itself, that it runs on a marked isolated clone.
 *
 * An E2E campaign writes through the HTTP API, not only through the guarded recipe commands : a host name
 * (127.0.0.1, leezr.test…) proves nothing about the database behind it. Before any spec runs, the Playwright
 * global setup asks the target for this proof and compares it with E2E_DB_DATABASE.
 *
 * Same decision as the recipe commands (RecipeDatabaseGuard::decide, read on the live connection). Anything
 * that is not a marked clone answers 404 and reveals nothing ; the route is not registered in production.
 * Read-only, nothing is logged or written.
 */
class RecipeIdentityController
{
    public function __invoke(RecipeDatabaseGuard $guard): JsonResponse
    {
        $identity = $guard->identify();
        if (app()->isProduction() || RecipeDatabaseGuard::decide($identity, (string) app()->environment()) !== []) {
            return response()->json(['clone' => false], 404);
        }

        return response()->json(['clone' => true, 'database' => $identity['actual']]);
    }
}
