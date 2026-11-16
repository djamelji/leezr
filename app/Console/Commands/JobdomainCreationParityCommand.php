<?php

namespace App\Console\Commands;

use App\Core\Jobdomains\Modernization\CreationParity;
use Illuminate\Console\Command;

/**
 * ADR-798 — measured parity historical vs versioned creation, on two FICTIVE companies inside a transaction
 * that is always rolled back (no residue). `--from-legacy` drafts / publishes / recommends presets from the
 * legacy configuration inside the same rolled-back transaction.
 */
class JobdomainCreationParityCommand extends Command
{
    protected $signature = 'jobdomains:creation-parity {jobdomain} {--market=} {--plan=} {--from-legacy} {--json}';

    protected $description = 'Compare historical and versioned company creation on fictive companies (rolled back)';

    public function handle(): int
    {
        $r = CreationParity::measure($this->argument('jobdomain'), $this->option('market') ?: null, $this->option('plan') ?: null, (bool) $this->option('from-legacy'));
        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->line("Recommendations: ".implode(', ', $r['recommendations']));
        $this->line("Bugs: {$r['bugs']} · voluntary differences: {$r['voluntary']} · technical parity: ".($r['technical_parity'] ? 'yes' : 'no'));
        foreach ($r['differences'] as $d) {
            $this->line(sprintf('  [%s] %s · %s : %s → %s', $d['class'], $d['dimension'], $d['item'], json_encode($d['legacy']), json_encode($d['versioned'])));
        }

        return self::SUCCESS;
    }
}
