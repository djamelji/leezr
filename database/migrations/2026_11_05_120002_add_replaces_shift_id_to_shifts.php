<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart Planning Lot 3 complément (ADR-717) — remplacement guidé.
 *
 * Additif : lien d'audit entre une affectation initiale impactée par une absence et le Shift de
 * remplacement créé. Le Shift initial publié n'est JAMAIS supprimé ni réaffecté ; le remplacement est
 * un NOUVEAU shift pointant vers lui. Nullable → aucun impact sur les shifts existants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_shifts', function (Blueprint $table) {
            $table->foreignId('replaces_shift_id')->nullable()->after('employee_id')
                ->constrained('workforce_shifts')->nullOnDelete();
            $table->index(['company_id', 'replaces_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workforce_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_shift_id');
        });
    }
};
