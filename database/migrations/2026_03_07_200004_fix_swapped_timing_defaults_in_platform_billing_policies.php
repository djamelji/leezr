<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix swapped values: upgrade should be immediate, downgrade should be end_of_period
        DB::table('platform_billing_policies')
            ->where('upgrade_timing', 'end_of_period')
            ->where('downgrade_timing', 'immediate')
            ->update([
                'upgrade_timing' => 'immediate',
                'downgrade_timing' => 'end_of_period',
            ]);
    }

    public function down(): void
    {
        // Revert to the (incorrect) swapped state
        DB::table('platform_billing_policies')
            ->where('upgrade_timing', 'immediate')
            ->where('downgrade_timing', 'end_of_period')
            ->update([
                'upgrade_timing' => 'end_of_period',
                'downgrade_timing' => 'immediate',
            ]);
    }
};
