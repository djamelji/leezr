<?php

namespace Tests\Support;

use App\Console\Recipe\RecipeDatabaseGuard;

/**
 * ADR-827 — PHPUnit only : lets a test exercise the LOGIC of a recipe command on its in-memory database.
 * Bound explicitly by the test (container instance) ; never reachable from a real process.
 */
class AllowingRecipeGuard extends RecipeDatabaseGuard
{
    public function assess(?string $command = null, ?string $connection = null): array
    {
        return ['allowed' => true, 'reasons' => [], 'identity' => ['test' => 'AllowingRecipeGuard']];
    }

    public function authorizeMaintenance(string $command, ?string $confirmDatabase, ?string $connection = null): array
    {
        return ['allowed' => true, 'mode' => 'test', 'reasons' => [], 'identity' => ['test' => 'AllowingRecipeGuard']];
    }
}
