<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-632 (M3) — éligibilité contractuelle V2 par abonnement, ADDITIVE.
 *
 * `v2_effective_at` : date d'effet de la bascule consolidée V2 (NULL = reste V1, défaut → aucun
 * contrat réel basculé). `commercial_policy_version` : version de politique commerciale figée.
 *
 * Deux gardes INDÉPENDANTES routent une activation vers V2 : (1) cette éligibilité + (2) le flag
 * opérationnel `billing.consolidated.route_activations` (OFF par défaut). Le flux V1 reste strictement
 * inchangé tant que les deux ne sont pas vrais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dateTime('v2_effective_at')->nullable()->after('plan_price_source');
            $table->string('commercial_policy_version', 20)->nullable()->after('v2_effective_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['v2_effective_at', 'commercial_policy_version']);
        });
    }
};
