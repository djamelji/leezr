<?php

namespace App\Console\Commands;

use App\Core\Markets\Jobs\FxRateFetchJob;
use Illuminate\Console\Command;

class FxRatesSyncCommand extends Command
{
    protected $signature = 'fx:rates-sync';

    protected $description = 'Synchronize FX exchange rates';

    public function handle(): int
    {
        (new FxRateFetchJob)->handle();

        return self::SUCCESS;
    }
}
