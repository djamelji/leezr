<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For every company_modules row where is_enabled_for_company = true,
        // create an activation_reason = 'direct'.
        // company_modules becomes a derived cache — activation_reasons is the source of truth.
        $rows = DB::table('company_modules')
            ->where('is_enabled_for_company', true)
            ->get(['company_id', 'module_key']);

        $now = now();
        $inserts = [];

        foreach ($rows as $row) {
            $inserts[] = [
                'company_id' => $row->company_id,
                'module_key' => $row->module_key,
                'reason' => 'direct',
                'source_module_key' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($inserts)) {
            // Chunk to avoid memory issues on large datasets
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('company_module_activation_reasons')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        // Remove all backfilled 'direct' reasons (best effort)
        DB::table('company_module_activation_reasons')
            ->where('reason', 'direct')
            ->whereNull('source_module_key')
            ->delete();
    }
};
