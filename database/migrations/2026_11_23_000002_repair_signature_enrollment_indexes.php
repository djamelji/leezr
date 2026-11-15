<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-793.1 — sur MySQL, 2026_10_30_150000 échouait sur un nom d'index de 68 caractères APRÈS la création
// de la table et des clés étrangères : les bases existantes ont la table sans ses deux index. Réparation
// additive : n'ajoute que l'index absent, détecté par ses COLONNES (quel que soit son nom), ne supprime
// ni ne renomme rien, ne touche aucune donnée. Rejouable.
return new class extends Migration
{
    private const TABLE = 'workforce_signature_enrollments';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $wanted = [
            'wf_sig_enroll_signer_created_idx' => ['signature_signer_id', 'created_at'],
            'wf_sig_enroll_company_idx' => ['company_id'],
        ];
        $existing = array_map(fn ($i) => $i['columns'], Schema::getIndexes(self::TABLE));
        foreach ($wanted as $name => $columns) {
            if (in_array($columns, $existing, true)) {
                continue;
            }
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        // Non destructif : ces index appartiennent au schéma de 2026_10_30_150000 ; on ne les retire pas ici.
    }
};
