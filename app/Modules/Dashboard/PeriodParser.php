<?php

namespace App\Modules\Dashboard;

use Carbon\Carbon;

final class PeriodParser
{
    public static function parse(string $period): Carbon
    {
        return match ($period) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };
    }
}
