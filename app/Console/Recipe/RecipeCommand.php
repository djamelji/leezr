<?php

namespace App\Console\Recipe;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * ADR-827 — base of every recipe (E2E fixture) command : `e2e:*` and any command that deletes, resets or rewrites
 * data for a test campaign.
 *
 * The identity check runs at the REAL point of execution — Symfony `execute()`, reached by `php artisan`,
 * `Artisan::call()`, `$this->call()` and child processes alike — before `handle()`. No option (`--force`
 * included) is consulted : a refused database is refused.
 *
 * The command body runs in ONE transaction : an exception or a failure exit code rolls everything back, and a
 * process killed mid-way leaves no partial write (the uncommitted transaction dies with the connection).
 */
abstract class RecipeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verdict = app(RecipeDatabaseGuard::class)->assess($this->getName());
        if (! $verdict['allowed']) {
            $this->error('RECIPE GUARD — refused : '.$this->getName().' only runs on a marked isolated clone.');
            foreach ($verdict['reasons'] as $reason) {
                $this->line("  - {$reason}");
            }
            $this->line('  Nothing was written. See ADR-827 (scripts/recipe/make-clone.sh).');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            $code = parent::execute($input, $output);
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
        $code === self::SUCCESS ? $connection->commit() : $connection->rollBack();

        return $code;
    }
}
