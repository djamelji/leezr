<?php

namespace App\Modules\Workforce\Listeners;

use App\Core\Events\ModuleEnabled;
use Database\Seeders\LeaveTypeSeeder;

/**
 * W-HR-UX-5 (P3) — initialise les types de congés AUTORISÉS d'une company dès que le module
 * `workforce_leave` est activé (les verticales activent workforce en ADDON, pas au jobdomain :
 * le bon hook est donc l'activation du module, pas la création de la company).
 *
 * Idempotent : `LeaveTypeSeeder::seedForCompany` fait des `updateOrCreate` sur (company_id, code)
 * = contrainte unique DB `wlt_company_code_unique`. Ré-activer le module ne duplique rien.
 *
 * Les droits (accrual) ne sont PAS crédités ici : seuls les TYPES configurés par market sont posés.
 * L'acquisition se déclenche à l'embauche / périodiquement, uniquement pour les types dont une
 * règle d'accrual est configurée (voir AccrueLeaveUseCase + commandes leave:accrue / leave:consume-due).
 */
class SeedLeaveTypesOnModuleEnabled
{
    public function handle(ModuleEnabled $event): void
    {
        if ($event->moduleKey !== 'workforce_leave') {
            return;
        }

        app(LeaveTypeSeeder::class)->seedForCompany(
            $event->company->id,
            $event->company->market_key ?: 'FR',
        );
    }
}
