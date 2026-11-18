<?php

namespace App\Console\Commands;

use App\Console\Recipe\RecipeDatabaseGuard;
use Illuminate\Console\Command;

/** ADR-827 — read-only : would a recipe (E2E fixture) command be allowed on the current connection, and why. */
class RecipeStatusCommand extends Command
{
    protected $signature = 'recipe:status';

    protected $description = 'Show whether the current database is a marked isolated clone (read-only) — ADR-827';

    public function handle(RecipeDatabaseGuard $guard): int
    {
        $verdict = $guard->assess('recipe:status');
        $this->line(json_encode($verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $verdict['allowed'] ? self::SUCCESS : self::FAILURE;
    }
}
