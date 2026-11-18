<?php

namespace App\Console\Commands;

use App\Console\Recipe\RecipeCommand;

/**
 * ADR-828 — the ONE entry point for the PHP fixtures of the E2E specs (it replaces `php artisan tinker
 * --execute`, which no guard covers). The file is a spec-written fixture script (setup, read-back, cleanup).
 *
 * As every recipe command : refused unless the live database is a marked isolated clone (secondary
 * connections included), and the script runs in one transaction (an exception rolls everything back).
 * Output is whatever the script prints ; the specs parse it as before.
 */
class E2EPhpCommand extends RecipeCommand
{
    protected $signature = 'e2e:php {file : PHP fixture script written by the spec}';

    protected $description = 'Run an E2E fixture script on a marked isolated clone only (ADR-828)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Fixture script not found : {$file}");

            return self::FAILURE;
        }

        (static function (string $__file): void {
            require $__file;
        })($file);

        return self::SUCCESS;
    }
}
