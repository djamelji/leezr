<?php

namespace App\Console\Commands;

use App\Core\Workforce\Dsn\DsnGatewayHealthCheck;
use App\Core\Workforce\Dsn\DsnMetricsService;
use Illuminate\Console\Command;

/**
 * Display DSN gateway health status and metrics.
 *
 * Sprint 7.5 — ADR-533
 */
class DsnGatewayHealthCommand extends Command
{
    protected $signature = 'dsn:gateway-health
        {--metrics : Include gateway metrics in output}';

    protected $description = 'Display DSN gateway health status and operational readiness';

    public function handle(DsnGatewayHealthCheck $healthCheck, DsnMetricsService $metrics): int
    {
        $report = $healthCheck->check();

        $statusColor = match ($report['status']) {
            'green' => 'green',
            'yellow' => 'yellow',
            'red' => 'red',
            default => 'white',
        };

        $statusIcon = match ($report['status']) {
            'green' => '●',
            'yellow' => '◐',
            'red' => '○',
            default => '?',
        };

        $this->line(sprintf(
            '<fg=%s>%s DSN Gateway: %s</>  [%s]',
            $statusColor,
            $statusIcon,
            strtoupper($report['status']),
            $report['checked_at'],
        ));
        $this->line(str_repeat('─', 60));

        $rows = [];

        foreach ($report['checks'] as $check) {
            $icon = match ($check['status']) {
                'green' => '<fg=green>✓</>',
                'yellow' => '<fg=yellow>!</>',
                'red' => '<fg=red>✗</>',
                default => '?',
            };

            $rows[] = [$icon, $check['label'], $check['detail']];
        }

        $this->table(['', 'Check', 'Detail'], $rows);

        if ($this->option('metrics')) {
            $this->newLine();
            $this->line('<fg=cyan>Metrics</>');

            $data = $metrics->collect();
            $metricRows = [];

            foreach ($data as $key => $value) {
                if ($key === 'collected_at') {
                    continue;
                }
                $metricRows[] = [$key, $value ?? 'n/a'];
            }

            $this->table(['Metric', 'Value'], $metricRows);
        }

        return self::SUCCESS;
    }
}
