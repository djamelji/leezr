<?php

namespace App\Console\Commands;

use App\Core\Models\Company;
use Illuminate\Console\Command;

/**
 * W-SIGNATURE-2 (ADR-654) — seeding de déploiement DEV, NON destructif.
 *
 * Appelée par le script de déploiement DEV après `migrate --force` (additif) :
 *  - base vide (premier déploiement) → population initiale complète (DevSeeder) ;
 *  - base existante (recette en cours) → bootstrap IDEMPOTENT (DevBootstrapSeeder), aucune
 *    destruction : collaborateurs, contrats, documents, demandes et preuves PRÉSERVÉS.
 *
 * Le reset destructif n'est JAMAIS fait au déploiement : il passe par `leezr:dev-reset` (explicite).
 */
class DevBootstrapCommand extends Command
{
    protected $signature = 'leezr:dev-bootstrap';

    protected $description = 'Seeding DEV non destructif au déploiement (population initiale si vide, sinon bootstrap idempotent).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->warn('leezr:dev-bootstrap: ignoré en production.');

            return self::SUCCESS;
        }

        $empty = Company::withoutGlobalScopes()->count() === 0;
        if ($empty) {
            $this->info('Base DEV vide → population initiale (DevSeeder).');
            $this->call('db:seed', ['--class' => \Database\Seeders\DevSeeder::class, '--force' => true]);
        } else {
            $this->info('Base DEV existante → bootstrap idempotent (données de recette préservées).');
            $this->call('db:seed', ['--class' => \Database\Seeders\DevBootstrapSeeder::class, '--force' => true]);
        }

        return self::SUCCESS;
    }
}
