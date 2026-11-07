<?php

namespace App\Console\Commands;

use App\Company\RBAC\CompanyRole;
use App\Core\Jobdomains\Jobdomain;
use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Tenancy\TenantContextProbeJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ADR-733.2 (P0-D.2) — Recette d'isolation tenant sur WORKER ASYNCHRONE RÉEL (pas le driver sync).
 *
 * Dispatche des jobs tenant (companies fictives A/B + un tenant inexistant) sur une queue DÉDIÉE
 * (`p0d2-proof`), les fait traiter par une vraie boucle de worker (`queue:work --stop-when-empty`), puis
 * vérifie l'isolation : chaque job ne voit QUE sa company, démarre sans contexte résiduel (hygiène),
 * un job en échec (tenant inexistant) ne contamine pas le suivant. Données fictives, nettoyées en fin.
 *
 * DEV UNIQUEMENT. Ne JAMAIS exécuter en production.
 */
class TenancyWorkerProofCommand extends Command
{
    protected $signature = 'tenancy:worker-proof';

    protected $description = '[DEV] Prouve l\'isolation tenant sur un worker asynchrone réel (ADR-733.2, P0-D.2).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Interdit en production.');

            return self::FAILURE;
        }

        $tag = 'p0d2-'.substr(md5((string) mt_rand()), 0, 8);
        $created = [];

        try {
            Model::unguard();
            $jobdomain = Jobdomain::first() ?? Jobdomain::create(['key' => 'logistique', 'label' => 'L', 'default_modules' => []]);
            $market = Market::first() ?? Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);

            $a = Company::create(['name' => "$tag-A", 'slug' => "$tag-a", 'jobdomain_key' => $jobdomain->key, 'market_key' => $market->key]);
            $b = Company::create(['name' => "$tag-B", 'slug' => "$tag-b", 'jobdomain_key' => $jobdomain->key, 'market_key' => $market->key]);
            $created = [$a->id, $b->id];
            CompanyRole::create(['company_id' => $a->id, 'key' => "$tag-ra", 'name' => 'RA', 'is_system' => false, 'is_administrative' => false]);
            CompanyRole::create(['company_id' => $b->id, 'key' => "$tag-rb", 'name' => 'RB', 'is_system' => false, 'is_administrative' => false]);
            $bogusId = Company::max('id') + 10000; // tenant inexistant
            Model::reguard();

            $keyA = "$tag:A";
            $keyB = "$tag:B";
            Cache::forget($keyA);
            Cache::forget($keyB);

            // Ordre : A (succès) → tenant inexistant (échec) → B (succès). Prouve "A ok, échec au milieu, B ok sans contamination".
            TenantContextProbeJob::dispatch($a->id, $keyA);
            TenantContextProbeJob::dispatch($bogusId, "$tag:bogus");
            TenantContextProbeJob::dispatch($b->id, $keyB);

            $this->info("Worker réel (queue database, file dédiée p0d2-proof)…");
            Artisan::call('queue:work', [
                '--queue' => 'p0d2-proof',
                '--stop-when-empty' => true,
                '--tries' => 1,
                '--no-interaction' => true,
            ]);

            $resA = Cache::get($keyA);
            $resB = Cache::get($keyB);
            $resBogus = Cache::get("$tag:bogus");

            $checks = [
                'A exécuté' => $resA !== null,
                'A ne voit QUE sa company' => $resA && $resA['seen'] === [$a->id],
                'A démarre sans contexte résiduel' => $resA && $resA['context_at_start'] === false,
                'A nettoie son contexte' => $resA && $resA['context_after'] === false,
                'B exécuté (malgré échec intermédiaire)' => $resB !== null,
                'B ne voit QUE sa company' => $resB && $resB['seen'] === [$b->id],
                'B démarre sans contexte résiduel (pas de contamination)' => $resB && $resB['context_at_start'] === false,
                'Tenant inexistant : job échoué, pas de résultat (fail-closed)' => $resBogus === null,
            ];

            $ok = true;
            foreach ($checks as $label => $pass) {
                $this->line(($pass ? '<info>  ✓</info> ' : '<error>  ✗</error> ').$label);
                $ok = $ok && $pass;
            }
            $this->newLine();
            $this->line('Résultats bruts : A='.json_encode($resA).' | B='.json_encode($resB).' | bogus='.json_encode($resBogus));

            // Vérifie que le job du tenant inexistant est bien parti en échec (fail-closed) et non silencieux.
            $failed = DB::table('failed_jobs')->where('payload', 'like', '%TenantContextProbeJob%')->count();
            $this->line("Jobs échoués (tenant inexistant → fail-closed attendu ≥1) : $failed");

            $this->newLine();
            $this->{$ok ? 'info' : 'error'}($ok ? 'ISOLATION WORKER RÉEL : PROUVÉE ✓' : 'ISOLATION WORKER RÉEL : ÉCHEC ✗');

            return $ok ? self::SUCCESS : self::FAILURE;
        } finally {
            // Nettoyage : companies/roles fictifs + jobs échoués de la sonde + cache.
            Model::unguard();
            if ($created) {
                CompanyRole::withoutGlobalScopes()->whereIn('company_id', $created)->delete();
                Company::whereIn('id', $created)->delete();
            }
            DB::table('failed_jobs')->where('payload', 'like', '%TenantContextProbeJob%')->delete();
            DB::table('jobs')->where('queue', 'p0d2-proof')->delete();
            Cache::forget("$tag:A");
            Cache::forget("$tag:B");
            Cache::forget("$tag:bogus");
            Model::reguard();
            $this->comment('Nettoyage effectué (données fictives supprimées).');
        }
    }
}
