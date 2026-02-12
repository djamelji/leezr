<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // System data — always (idempotent, safe for all environments)
        $this->call(SystemSeeder::class);

        // Demo data — local only (never in staging/production)
        if (app()->environment('local')) {
            $this->call(DevSeeder::class);
        }
    }
}
