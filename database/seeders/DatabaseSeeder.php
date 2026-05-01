<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // System data — always (idempotent, safe for all environments)
        $this->call(SystemSeeder::class);
        $this->call(PaymentModuleSeeder::class);
        $this->call(HelpCenterSeeder::class);

        // Demo data — local only (never in staging/production)
        if (app()->environment('local')) {
            $this->call(DevSeeder::class);
            $this->call(FinanceDemoSeeder::class);

            // Re-run PaymentModuleSeeder to seed test card (needs company to exist)
            $this->call(PaymentModuleSeeder::class);
        }
    }
}
