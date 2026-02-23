<?php

use App\Core\Markets\Jobs\FxRateFetchJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FX rate refresh every 6 hours (ADR-104)
Schedule::job(new FxRateFetchJob)->cron('0 */6 * * *')->withoutOverlapping();
