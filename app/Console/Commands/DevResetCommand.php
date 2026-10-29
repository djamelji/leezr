<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * W-SIGNATURE-2 (ADR-654) — remise à zéro DEV EXPLICITE, distincte du déploiement.
 *
 * Le déploiement DEV ordinaire est désormais NON destructif (migrate --force + DevBootstrapSeeder).
 * Cette commande est le SEUL chemin de reset destructif en DEV : elle efface la base et recharge
 * les données de démo (DevSeeder). Garde-fous : jamais en production, confirmation explicite requise,
 * rappel de sauvegarde. Ne jamais détruire une base de recette sans autorisation.
 */
class DevResetCommand extends Command
{
    protected $signature = 'leezr:dev-reset
        {--force : Confirme la remise à zéro destructive (obligatoire)}
        {--i-have-a-backup : Confirme avoir réalisé une sauvegarde}';

    protected $description = 'Remise à zéro DESTRUCTIVE de la base DEV + données de démo (hors déploiement). DEV uniquement.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('REFUSÉ : commande interdite en production.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('Cette commande EFFACE toute la base DEV (collaborateurs, contrats, documents, demandes, preuves).');
            $this->line('Le déploiement ordinaire ne fait PLUS cela : utilisez cette commande uniquement pour un reset VOLONTAIRE.');
            $this->line('Relancez avec --force --i-have-a-backup après avoir sauvegardé.');

            return self::FAILURE;
        }

        if (! $this->option('i-have-a-backup')) {
            $this->error('REFUSÉ : --i-have-a-backup requis (sauvegarde vérifiée avant reset).');

            return self::FAILURE;
        }

        $this->warn('→ migrate:fresh --seed (reset destructif DEV)…');
        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->warn('→ DevSeeder (données de démo)…');
        $this->call('db:seed', ['--class' => \Database\Seeders\DevSeeder::class, '--force' => true]);
        $this->info('Reset DEV terminé.');

        return self::SUCCESS;
    }
}
