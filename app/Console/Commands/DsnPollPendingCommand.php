<?php

namespace App\Console\Commands;

use App\Core\Workforce\DsnDeclaration;
use App\Modules\Workforce\UseCases\PollDsnDeclarationUseCase;
use Illuminate\Console\Command;

/**
 * Poll submitted DSN declarations for status updates.
 *
 * Selects declarations that are submitted and due for polling
 * (next_poll_at <= now). Delegates to PollDsnDeclarationUseCase.
 *
 * Sprint 7.4 — ADR-532
 */
class DsnPollPendingCommand extends Command
{
    protected $signature = 'dsn:poll-pending
        {--limit=50 : Maximum declarations to poll per run}
        {--dry-run : Show what would be polled without executing}';

    protected $description = 'Poll submitted DSN declarations for gateway status updates';

    public function handle(PollDsnDeclarationUseCase $useCase): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        // Select due declarations
        $declarations = DsnDeclaration::query()
            ->where('status', DsnDeclaration::STATUS_SUBMITTED)
            ->where(function ($q) {
                $q->whereNull('next_poll_at')
                    ->orWhere('next_poll_at', '<=', now());
            })
            ->orderBy('next_poll_at')
            ->limit($limit)
            ->get();

        if ($declarations->isEmpty()) {
            $this->info('No declarations due for polling.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<fg=cyan>DSN Polling</> — %d declaration(s) due%s',
            $declarations->count(),
            $dryRun ? ' <fg=yellow>(dry-run)</>' : '',
        ));
        $this->line(str_repeat('─', 60));

        if ($dryRun) {
            $this->table(
                ['ID', 'Company', 'Period', 'Reference', 'Submitted', 'Next Poll'],
                $declarations->map(fn ($d) => [
                    $d->id,
                    $d->company_id,
                    $d->period_month,
                    $d->submission_reference,
                    $d->submitted_at?->format('Y-m-d H:i'),
                    $d->next_poll_at?->format('Y-m-d H:i') ?? 'now',
                ])->toArray(),
            );

            return self::SUCCESS;
        }

        // Process each declaration
        // Use actor_id = 0 for system/scheduler
        $actorId = 0;
        $results = ['accepted' => 0, 'rejected' => 0, 'pending' => 0, 'timeout' => 0, 'locked' => 0, 'error' => 0, 'skipped' => 0];

        foreach ($declarations as $declaration) {
            $outcome = $useCase->execute($declaration, $actorId);
            $result = $outcome['result'];

            // Normalize result keys
            $key = match (true) {
                str_starts_with($result, 'error') => 'error',
                array_key_exists($result, $results) => $result,
                default => 'error',
            };
            $results[$key]++;

            $statusIcon = match ($result) {
                'accepted' => '<fg=green>✓</>',
                'rejected' => '<fg=red>✗</>',
                'timeout' => '<fg=red>⏰</>',
                'pending' => '<fg=yellow>⋯</>',
                'locked' => '<fg=gray>🔒</>',
                'skipped' => '<fg=gray>→</>',
                default => '<fg=red>!</>',
            };

            $this->line(sprintf(
                '  %s #%d [%s] %s → %s',
                $statusIcon,
                $declaration->id,
                $declaration->period_month,
                $declaration->submission_reference ?? '?',
                $result,
            ));
        }

        // Summary
        $this->newLine();
        $this->line('<fg=cyan>Summary</>');
        $this->table(
            ['Result', 'Count'],
            collect($results)->filter(fn ($v) => $v > 0)->map(fn ($v, $k) => [$k, $v])->values()->toArray(),
        );

        return self::SUCCESS;
    }
}
