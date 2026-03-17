<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Consolidate 'both' → 'public' (ADR-356)
        // 'public' now means "visible to everyone" (anonymous + company users)
        DB::table('documentation_topics')->where('audience', 'both')->update(['audience' => 'public']);
        DB::table('documentation_articles')->where('audience', 'both')->update(['audience' => 'public']);
        DB::table('documentation_groups')->where('audience', 'both')->update(['audience' => 'public']);
    }

    public function down(): void
    {
        // Cannot reliably reverse — 'both' records are now 'public'
    }
};
