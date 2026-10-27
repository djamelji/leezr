<?php

namespace App\Console\Commands;

use App\Core\Billing\Migration\BillingV2MigrationSimulator;
use Illuminate\Console\Command;

/**
 * ADR-632 (M10-A) — audit de migration V2 en LECTURE SEULE. Inventorie les abonnements, reconstitue
 * la couverture V1 (watermarks + intervalles) en simulation et classe éligible / revue manuelle.
 * N'ÉCRIT RIEN. Volontairement SANS option --apply : la persistance des couvertures relève de M10-B
 * (autorisation PROD explicite requise).
 */
class BillingV2MigrationAuditCommand extends Command
{
    protected $signature = 'billing:v2-migration-audit
        {--company= : Restreindre à une Company (id)}
        {--json : Sortie JSON complète (rapport avant/après)}
        {--only=all : Filtrer: all|eligible|manual}';

    protected $description = 'Audit lecture seule de migration Billing V2 (couverture, éligibilité). N\'écrit rien.';

    public function handle(): int
    {
        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $result = BillingV2MigrationSimulator::simulateAll($companyId);

        $only = $this->option('only');
        $reports = collect($result['reports']);
        if ($only === 'eligible') {
            $reports = $reports->where('classification', 'eligible');
        } elseif ($only === 'manual') {
            $reports = $reports->where('classification', 'manual_review');
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('=== Audit migration Billing V2 (LECTURE SEULE — aucune écriture) ===');
        $this->line(sprintf(
            'Candidats: %d · Éligibles: %d · Revue manuelle: %d',
            $result['total_candidates'], $result['eligible_count'], $result['manual_review_count']
        ));

        if (! empty($result['block_reason_histogram'])) {
            $this->newLine();
            $this->line('Motifs de blocage:');
            foreach ($result['block_reason_histogram'] as $reason => $count) {
                $this->line(sprintf('  %-28s %d', $reason, $count));
            }
        }

        $rows = $reports->map(fn ($r) => [
            $r['company_id'],
            \Illuminate\Support\Str::limit((string) $r['company_name'], 22),
            $r['subscription_id'],
            $r['plan_key'],
            $r['currency'] ?? '—',
            $r['price_reliable'] ? 'fiable' : $r['price_source'],
            $r['current_period']['classification'] ?? '—',
            count($r['active_modules']),
            $r['v1_invoices_count'].'/'.$r['v1_annexes_count'],
            $r['after']['covered_intervals'],
            $r['overlap_risk'] ? 'OUI' : '—',
            $r['classification'] === 'eligible' ? '✓' : implode(',', $r['block_reasons']),
        ])->all();

        $this->newLine();
        $this->table(
            ['Co', 'Nom', 'Sub', 'Plan', 'Dev', 'Prix', 'Période', 'Mod', 'Inv/Anx', 'Couv', 'Overlap', 'Classement'],
            $rows
        );

        $this->newLine();
        $this->comment('Rappel: simulation uniquement. billing_watermarks / billing_covered_intervals NON modifiés.');

        return self::SUCCESS;
    }
}
