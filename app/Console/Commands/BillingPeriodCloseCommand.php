<?php

namespace App\Console\Commands;

use App\Console\Concerns\HasCorrelationId;
use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Billing\FinancialPeriod;
use App\Core\Billing\LedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-143 D3g: Close a financial period.
 *
 * Once closed, normal ledger entries for dates within the period
 * are rejected. Only adjustment entries remain possible.
 */
class BillingPeriodCloseCommand extends Command implements Isolatable
{
    use HasCorrelationId;

    protected $signature = 'billing:period-close
        {company : Company ID}
        {start : Period start date (Y-m-d)}
        {end : Period end date (Y-m-d)}
        {--dry-run : Preview without closing}';

    protected $description = 'Close a financial period for a company (prevents normal ledger writes within date range)';

    public function handle(AuditLogger $audit): int
    {
        $this->initCorrelationId();
        $companyId = (int) $this->argument('company');
        $startDate = $this->argument('start');
        $endDate = $this->argument('end');
        $dryRun = (bool) $this->option('dry-run');

        // Validate dates
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if (!$start || !$end) {
            $this->error('Invalid date format. Use Y-m-d.');

            return self::FAILURE;
        }

        if ($start > $end) {
            $this->error('Start date must be before or equal to end date.');

            return self::FAILURE;
        }

        // Check for overlapping closed period
        $overlap = FinancialPeriod::where('company_id', $companyId)
            ->where('is_closed', true)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->exists();

        if ($overlap) {
            $this->error('Overlapping closed period exists for this date range.');

            return self::FAILURE;
        }

        // Count ledger entries in the period
        $entryCount = LedgerEntry::where('company_id', $companyId)
            ->whereBetween('recorded_at', [
                $start->format('Y-m-d') . ' 00:00:00',
                $end->format('Y-m-d') . ' 23:59:59',
            ])
            ->count();

        // Trial balance within the period
        $balance = LedgerEntry::where('company_id', $companyId)
            ->whereBetween('recorded_at', [
                $start->format('Y-m-d') . ' 00:00:00',
                $end->format('Y-m-d') . ' 23:59:59',
            ])
            ->selectRaw('ROUND(SUM(debit), 2) as total_debit, ROUND(SUM(credit), 2) as total_credit')
            ->first();

        $totalDebit = (float) ($balance->total_debit ?? 0);
        $totalCredit = (float) ($balance->total_credit ?? 0);

        $this->info("Period: {$startDate} → {$endDate} | Company: {$companyId}");
        $this->info("Ledger entries: {$entryCount}");
        $this->info("Total debit: {$totalDebit} | Total credit: {$totalCredit}");

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            $this->warn('WARNING: Debit/credit imbalance detected in this period.');
        }

        if ($dryRun) {
            $this->info('[DRY-RUN] Period would be closed — no changes made.');

            return self::SUCCESS;
        }

        // Close the period
        DB::transaction(function () use ($companyId, $startDate, $endDate, $audit, $entryCount) {
            $period = FinancialPeriod::create([
                'company_id' => $companyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_closed' => true,
                'closed_at' => now(),
                'closed_by' => null, // CLI — no authenticated user
            ]);

            $audit->logPlatform(
                AuditAction::BILLING_PERIOD_CLOSED,
                'company',
                (string) $companyId,
                [
                    'severity' => 'critical',
                    'metadata' => [
                        'period_id' => $period->id,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'entry_count' => $entryCount,
                    ],
                ],
            );
        });

        $this->info('Period closed successfully.');

        return self::SUCCESS;
    }
}
