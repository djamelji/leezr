<?php

namespace App\Console\Recipe;

use Illuminate\Console\Command;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
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
 * ADR-828 — SECONDARY connections are checked too : every connection already open when the command starts,
 * and every connection opened while it runs (`ConnectionEstablished`, raised before the first query) must
 * itself be a marked clone ; otherwise it is discarded and the command aborts (rolled back).
 *
 * The command body runs in ONE transaction on the default connection : an exception or a failure exit code
 * rolls everything back, and a process killed mid-way leaves no partial write.
 */
abstract class RecipeCommand extends Command
{
    private bool $guarding = false;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $guard = app(RecipeDatabaseGuard::class);
        $default = config('database.default');
        $refusals = [];
        $verdict = $guard->assess($this->getName());
        if (! $verdict['allowed']) {
            $refusals = $verdict['reasons'];
        }
        foreach (array_keys(DB::getConnections()) as $name) {
            if ($name !== $default && ! ($v = $guard->assess($this->getName(), $name))['allowed']) {
                $refusals[] = "secondary connection {$name} : ".implode(' ; ', $v['reasons']);
            }
        }
        if ($refusals !== []) {
            $this->error('RECIPE GUARD — refused : '.$this->getName().' only runs on a marked isolated clone.');
            foreach ($refusals as $reason) {
                $this->line("  - {$reason}");
            }
            $this->line('  Nothing was written. See ADR-827 / ADR-828 (scripts/recipe/make-clone.sh).');

            return self::FAILURE;
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($guard, $default) {
            if (! $this->guarding || $event->connectionName === $default) {
                return;
            }
            $v = $guard->assess($this->getName(), $event->connectionName);
            if (! $v['allowed']) {
                DB::purge($event->connectionName);

                throw new RuntimeException("RECIPE GUARD — secondary connection {$event->connectionName} refused : ".implode(' ; ', $v['reasons']));
            }
        });

        $connection = DB::connection();
        $connection->beginTransaction();
        $this->guarding = true;
        try {
            $code = parent::execute($input, $output);
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        } finally {
            $this->guarding = false;
        }
        $code === self::SUCCESS ? $connection->commit() : $connection->rollBack();

        return $code;
    }
}
