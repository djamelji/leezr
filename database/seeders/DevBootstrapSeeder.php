<?php

namespace Database\Seeders;

use App\Core\Models\Company;
use App\Core\Modules\ModuleActivationEngine;
use App\Core\Modules\PlatformModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * W-SIGNATURE-2 (ADR-654) — bootstrap DEV IDEMPOTENT et NON DESTRUCTIF.
 *
 * Exécuté à CHAQUE déploiement DEV ordinaire (après `migrate --force` additif). Contrairement à
 * `DevSeeder` (remise à zéro complète + données de démo), ce seeder ne crée AUCUNE donnée de démo
 * et ne supprime RIEN : il se contente de (ré)garantir la configuration qui doit survivre entre
 * releases — en particulier l'entitlement de TEST et l'activation des modules Contract/Signature
 * pour Leezr Logistics (identité stable par slug, pas d'ID en dur). Les collaborateurs, contrats,
 * documents, demandes et preuves créés pendant la recette sont donc PRÉSERVÉS.
 *
 * Jamais exécuté en PROD (garde + non branché sur la branche main du script de déploiement).
 */
class DevBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            Log::warning('DevBootstrapSeeder: skipped on production.');

            return;
        }

        $company = Company::withoutGlobalScopes()->where('slug', 'leezr-logistics')->first();
        if (! $company) {
            // Base fraîche sans données : le bootstrap ne fabrique pas de company ici.
            // Une remise à zéro explicite (artisan leezr:dev-reset) exécute le DevSeeder complet.
            Log::info('DevBootstrapSeeder: leezr-logistics absent — rien à amorcer (utiliser leezr:dev-reset).');

            return;
        }

        // Entitlement de TEST (DEV-only) : rend contract/signature disponibles SANS productisation PROD
        // (ModuleProductConfigSeeder, qui tourne en PROD, ne les contient volontairement pas).
        foreach (['workforce_contract', 'workforce_signature'] as $key) {
            $pm = PlatformModule::where('key', $key)->first();
            if ($pm && $pm->addon_pricing === null) {
                $pm->update(['is_enabled_globally' => true, 'addon_pricing' => ['dev_test' => true, 'price_monthly' => 0]]);
            }
        }

        // Activation idempotente des modules pour Leezr Logistics (contract/signature après leur
        // dépendance workforce_documents). N'active AUCUN autre company.
        foreach (['workforce', 'workforce_leave', 'workforce_planning', 'workforce_payroll', 'workforce_documents', 'workforce_contract', 'workforce_signature'] as $key) {
            try {
                ModuleActivationEngine::enable($company, $key);
            } catch (\Throwable $e) {
                Log::warning("DevBootstrapSeeder: enable {$key} failed: ".$e->getMessage());
            }
        }
    }
}
