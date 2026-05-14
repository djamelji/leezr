<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Models\Company;
use App\Core\Workforce\DsnDeclaration;
use App\Core\Workforce\Dsn\DsnBlockSerializer;
use App\Core\Workforce\Dsn\DsnCompanyResolver;
use App\Core\Workforce\Dsn\DsnCtpAggregate;
use App\Core\Workforce\Dsn\DsnCtpMapping;
use App\Core\Workforce\Dsn\DsnEmployeeBlock;
use App\Core\Workforce\Dsn\DsnEmployeeResolver;
use App\Core\Workforce\Dsn\DsnPayload;
use App\Core\Workforce\Dsn\DsnValidator;
use App\Core\Workforce\EmploymentContract;
use App\Core\Workforce\PayrollCalculation;
use App\Core\Workforce\PayrollLine;
use App\Core\Workforce\PayrollRun;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates DSN export from a validated/exported PayrollRun.
 *
 * Pipeline: build DsnPayload → validate → serialize → persist file → create DsnDeclaration.
 *
 * Pure read-only on PayrollRun — ZERO mutation of payroll data.
 * No transmission to net-entreprises (future Phase 7).
 *
 * Sprint 6.4 — ADR-522
 */
class ExportPayrollDsnUseCase
{
    /**
     * Export a PayrollRun as a DSN declaration.
     *
     * @param  PayrollRun  $run       Must be validated or exported
     * @param  int         $actorId   User performing the export
     * @return DsnDeclaration         Created or updated declaration
     *
     * @throws \DomainException if run status invalid or validation fails
     */
    public function execute(PayrollRun $run, int $actorId): DsnDeclaration
    {
        // Guard: status must be validated or exported
        if (! in_array($run->status, [PayrollRun::STATUS_VALIDATED, PayrollRun::STATUS_EXPORTED], true)) {
            throw new \DomainException(
                "Cannot generate DSN: PayrollRun status is '{$run->status}', expected 'validated' or 'exported'."
            );
        }

        // Guard: all calculations must be validated
        $lines = PayrollLine::withoutGlobalScopes()
            ->where('payroll_run_id', $run->id)
            ->with(['employee'])
            ->get();

        $calculations = PayrollCalculation::withoutGlobalScopes()
            ->whereIn('payroll_line_id', $lines->pluck('id'))
            ->where('status', PayrollCalculation::STATUS_VALIDATED)
            ->get()
            ->keyBy('payroll_line_id');

        $linesWithoutCalc = $lines->filter(fn ($l) => ! $calculations->has($l->id));
        if ($linesWithoutCalc->isNotEmpty()) {
            throw new \DomainException(
                'Cannot generate DSN: ' . $linesWithoutCalc->count() . ' payroll line(s) have no validated calculation.'
            );
        }

        // Idempotency: check if declaration already exists for this run
        $existing = DsnDeclaration::withoutGlobalScopes()
            ->where('company_id', $run->company_id)
            ->where('payroll_run_id', $run->id)
            ->first();

        if ($existing) {
            // Submitted or accepted — immutable, cannot regenerate
            if (! $existing->canRegenerate()) {
                throw new \DomainException(
                    "Cannot regenerate DsnDeclaration: status is '{$existing->status}'. "
                    . 'Only draft, validated, exported, or rejected declarations can be regenerated.'
                );
            }

            // Exported — return as-is (idempotent)
            if ($existing->isExported()) {
                return $existing;
            }

            // Rejected — delete old record so we can create a fresh one
            // (unique constraint on company_id + payroll_run_id)
            if ($existing->status === DsnDeclaration::STATUS_REJECTED) {
                $existing->delete();
                $existing = null;
            }
        }

        // Resolve company data
        $company = Company::withoutGlobalScopes()->findOrFail($run->company_id);
        $companyData = DsnCompanyResolver::resolve($company);

        // Resolve employee data (batch — avoids N+1)
        $employees = $lines->pluck('employee')->filter();
        $employeeDataMap = DsnEmployeeResolver::resolveBatch($employees);

        // Build employee blocks
        $employeeBlocks = [];
        foreach ($lines as $line) {
            $calc = $calculations->get($line->id);
            $empData = $employeeDataMap[$line->employee_id] ?? null;

            if (! $empData || ! $calc) {
                continue;
            }

            $employeeBlocks[] = $this->buildEmployeeBlock($line, $calc, $empData, $run, $company);
        }

        // Build CTP aggregates
        $ctpAggregates = $this->buildCtpAggregates($employeeBlocks);

        // Period month
        $periodMonth = date('Y-m', strtotime($run->period_start));

        // Build payload
        $payload = new DsnPayload(
            declarationType: 'mensuelle',
            nature: '01',
            periodStart: $run->period_start instanceof \DateTimeInterface
                ? $run->period_start->format('Y-m-d')
                : (string) $run->period_start,
            periodEnd: $run->period_end instanceof \DateTimeInterface
                ? $run->period_end->format('Y-m-d')
                : (string) $run->period_end,
            fraction: '11',
            payrollRunId: $run->id,
            company: $companyData,
            employees: $employeeBlocks,
            ctpAggregates: $ctpAggregates,
            createdAt: now()->toIso8601String(),
        );

        // Validate
        $validationResult = DsnValidator::validate($payload);

        // Serialize (even if validation fails — store for debugging)
        $serialized = DsnBlockSerializer::serialize($payload);
        $payloadHash = hash('sha256', $serialized);

        // Persist file
        $filePath = $this->persistFile($run->company_id, $periodMonth, $serialized);

        // Create or update DsnDeclaration
        $declaration = $existing ?? new DsnDeclaration();
        $declaration->fill([
            'company_id' => $run->company_id,
            'payroll_run_id' => $run->id,
            'declaration_type' => 'monthly',
            'period_month' => $periodMonth,
            'payload_hash' => $payloadHash,
            'file_path' => $filePath,
            'validation_errors' => $validationResult->valid ? null : $validationResult->entries,
            'payload_snapshot' => $payload->toArray(),
            'generated_by' => $actorId,
            'generated_at' => now(),
        ]);

        if ($validationResult->valid) {
            $declaration->status = DsnDeclaration::STATUS_VALIDATED;
        } else {
            $declaration->status = DsnDeclaration::STATUS_DRAFT;
        }

        $declaration->save();

        // Audit log
        app(AuditLogger::class)->logCompany(
            companyId: $run->company_id,
            action: 'dsn_declaration.generated',
            targetType: 'dsn_declaration',
            targetId: (string) $declaration->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.dsn',
                    'description' => "DSN declaration generated for period {$periodMonth}",
                    'period_month' => $periodMonth,
                    'payroll_run_id' => $run->id,
                    'employee_count' => count($employeeBlocks),
                    'validation_valid' => $validationResult->valid,
                    'validation_error_count' => count($validationResult->errors()),
                    'validation_warning_count' => count($validationResult->warnings()),
                    'payload_hash' => $payloadHash,
                    'status' => $declaration->status,
                ],
            ],
        );

        // If validation failed, throw after persisting (errors are stored)
        if (! $validationResult->valid) {
            $blockingErrors = $validationResult->errors();

            throw new \DomainException(
                'DSN validation failed with ' . count($blockingErrors) . ' error(s). '
                . 'Declaration saved as draft (ID: ' . $declaration->id . '). '
                . 'Errors: ' . json_encode(array_column($blockingErrors, 'message'))
            );
        }

        return $declaration;
    }

    /**
     * Build a DsnEmployeeBlock from PayrollLine + PayrollCalculation.
     */
    private function buildEmployeeBlock(
        PayrollLine $line,
        PayrollCalculation $calc,
        \App\Core\Workforce\Dsn\DsnEmployeeData $empData,
        PayrollRun $run,
        Company $company,
    ): DsnEmployeeBlock {
        // Contract data (from compensation_snapshot or contract)
        $compSnapshot = $line->compensation_snapshot ?? [];
        $contractId = $compSnapshot['contract_id'] ?? null;
        $contract = $contractId
            ? EmploymentContract::withoutGlobalScopes()->find($contractId)
            : null;

        $weeklyHours = (float) ($compSnapshot['weekly_hours'] ?? 35);
        $weeklyHoursHundredths = (string) (int) ($weeklyHours * 100);

        $contractData = [
            'start_date' => $contract?->start_date?->format('Y-m-d') ?? $empData->hireDate ?? '',
            'status_code' => $this->mapContractStatusCode($contract),
            'nature_code' => $this->mapContractNatureCode($contract),
            'contract_number' => $contract ? (string) $contract->id : '',
            'company_weekly_hours_hundredths' => '3500',
            'contract_weekly_hours_hundredths' => $weeklyHoursHundredths,
            'idcc' => $company->defaultConventionCollective?->idcc ?? '',
        ];

        // Payment data
        $paymentData = [
            'payment_date' => $run->period_end instanceof \DateTimeInterface
                ? $run->period_end->format('Y-m-d')
                : (string) $run->period_end,
            'net_taxable_cents' => $calc->taxable_income_cents ?? 0,
            'net_payable_cents' => $calc->net_payable_cents ?? 0,
            'tax_rate_bps' => $calc->tax_breakdown['tax_rate_bps'] ?? 0,
            'tax_rate_type' => '01',
            'tax_cents' => $calc->tax_cents ?? 0,
        ];

        // Remuneration lines
        $remunerationLines = $this->buildRemunerationLines($line, $run);

        // CSG/CRDS bases
        $csgCrdsLines = $this->buildCsgCrdsLines($calc, $run);

        // Individual contribution lines (excluding CSG/CRDS)
        $contributionLines = $this->buildContributionLines($calc);

        return new DsnEmployeeBlock(
            identity: $empData,
            contract: $contractData,
            payment: $paymentData,
            remunerationLines: $remunerationLines,
            csgCrdsLines: $csgCrdsLines,
            contributionLines: $contributionLines,
            payrollLineId: $line->id,
        );
    }

    /**
     * Build remuneration lines for S21.G00.51.
     */
    private function buildRemunerationLines(PayrollLine $line, PayrollRun $run): array
    {
        $periodStart = $run->period_start instanceof \DateTimeInterface
            ? $run->period_start->format('Y-m-d')
            : (string) $run->period_start;
        $periodEnd = $run->period_end instanceof \DateTimeInterface
            ? $run->period_end->format('Y-m-d')
            : (string) $run->period_end;

        $breakdown = $line->gross_breakdown ?? [];
        $lines = [];

        // Type 001 — Salaire brut de base
        $baseHours = $breakdown['base_hours'] ?? 0;
        $baseSalaryCents = $breakdown['base_salary_cents'] ?? $line->base_salary_cents ?? 0;
        if ($baseSalaryCents > 0) {
            $lines[] = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'type_code' => '001',
                'hours' => (float) $baseHours,
                'amount_cents' => $baseSalaryCents,
            ];
        }

        // Type 002 — Heures supplémentaires 25%
        $ot25Cents = $breakdown['overtime_25_cents'] ?? 0;
        $ot25Hours = $breakdown['overtime_25_hours'] ?? 0;
        if ($ot25Cents > 0) {
            $lines[] = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'type_code' => '002',
                'hours' => (float) $ot25Hours,
                'amount_cents' => $ot25Cents,
            ];
        }

        // Type 003 — Heures supplémentaires 50%
        $ot50Cents = $breakdown['overtime_50_cents'] ?? 0;
        $ot50Hours = $breakdown['overtime_50_hours'] ?? 0;
        if ($ot50Cents > 0) {
            $lines[] = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'type_code' => '003',
                'hours' => (float) $ot50Hours,
                'amount_cents' => $ot50Cents,
            ];
        }

        // Type 010 — Heures supplémentaires structurelles (daily overtime)
        $otDailyCents = $breakdown['overtime_daily_cents'] ?? 0;
        $otDailyHours = $breakdown['overtime_daily_hours'] ?? 0;
        if ($otDailyCents > 0) {
            $lines[] = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'type_code' => '010',
                'hours' => (float) $otDailyHours,
                'amount_cents' => $otDailyCents,
            ];
        }

        return $lines;
    }

    /**
     * Build CSG/CRDS base lines for S21.G00.78.
     */
    private function buildCsgCrdsLines(PayrollCalculation $calc, PayrollRun $run): array
    {
        $periodStart = $run->period_start instanceof \DateTimeInterface
            ? $run->period_start->format('Y-m-d')
            : (string) $run->period_start;
        $periodEnd = $run->period_end instanceof \DateTimeInterface
            ? $run->period_end->format('Y-m-d')
            : (string) $run->period_end;

        $contribLines = $calc->contribution_lines ?? [];
        $csgLines = [];

        foreach ($contribLines as $cl) {
            $code = $cl['code'] ?? '';
            if (! DsnCtpMapping::isCsgCrds($code)) {
                continue;
            }

            // Base code mapping for S21.G00.78
            $baseCode = match ($code) {
                'csg_deductible' => '02',       // CSG déductible
                'csg_non_deductible' => '03',   // CSG non déductible (imposable)
                'crds' => '04',                 // CRDS
                default => '02',
            };

            $csgLines[] = [
                'base_code' => $baseCode,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount_cents' => $cl['base_cents'] ?? 0,
            ];
        }

        return $csgLines;
    }

    /**
     * Build individual contribution lines for S21.G00.81 (excluding CSG/CRDS).
     */
    private function buildContributionLines(PayrollCalculation $calc): array
    {
        $contribLines = $calc->contribution_lines ?? [];
        $result = [];

        foreach ($contribLines as $cl) {
            $code = $cl['code'] ?? '';
            if (empty($code)) {
                continue;
            }

            $result[] = [
                'code' => $code,
                'base_cents' => $cl['base_cents'] ?? 0,
                'employee_cents' => $cl['employee_cents'] ?? 0,
                'employer_cents' => $cl['employer_cents'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Build CTP aggregates from employee blocks.
     *
     * @param  DsnEmployeeBlock[]  $blocks
     * @return DsnCtpAggregate[]
     */
    private function buildCtpAggregates(array $blocks): array
    {
        $byCtp = [];

        foreach ($blocks as $block) {
            foreach ($block->contributionLines as $line) {
                $code = $line['code'] ?? '';
                $ctpCode = DsnCtpMapping::ctpCode($code);
                if ($ctpCode === null) {
                    continue; // CSG/CRDS or unmapped
                }

                if (! isset($byCtp[$ctpCode])) {
                    $mapping = DsnCtpMapping::get($code);
                    $byCtp[$ctpCode] = [
                        'label' => $mapping['ctp_label'],
                        'base' => 0,
                        'ee' => 0,
                        'er' => 0,
                        'employees' => [],
                    ];
                }

                $byCtp[$ctpCode]['base'] += ($line['base_cents'] ?? 0);
                $byCtp[$ctpCode]['ee'] += ($line['employee_cents'] ?? 0);
                $byCtp[$ctpCode]['er'] += ($line['employer_cents'] ?? 0);

                $empId = $block->identity->employeeId;
                if (! in_array($empId, $byCtp[$ctpCode]['employees'], true)) {
                    $byCtp[$ctpCode]['employees'][] = $empId;
                }
            }
        }

        $aggregates = [];
        foreach ($byCtp as $ctpCode => $data) {
            $aggregates[] = new DsnCtpAggregate(
                ctpCode: $ctpCode,
                ctpLabel: $data['label'],
                baseCents: $data['base'],
                employeeCents: $data['ee'],
                employerCents: $data['er'],
                employeeCount: count($data['employees']),
            );
        }

        return $aggregates;
    }

    /**
     * Persist serialized DSN file to storage.
     */
    private function persistFile(int $companyId, string $periodMonth, string $content): string
    {
        $fileName = "dsn_{$companyId}_{$periodMonth}_" . now()->format('Ymd_His') . '.dsn';
        $filePath = "workforce/dsn/{$companyId}/{$fileName}";

        Storage::disk('local')->put($filePath, $content);

        return $filePath;
    }

    /**
     * Map contract type to DSN status code (S21.G00.40.002).
     */
    private function mapContractStatusCode(?EmploymentContract $contract): string
    {
        if (! $contract) {
            return '01'; // Default: CDI
        }

        return match ($contract->contract_type) {
            'cdi' => '01',
            'cdd' => '02',
            'stage' => '29',
            'alternance' => '07',
            default => '01',
        };
    }

    /**
     * Map contract type to DSN nature code (S21.G00.40.007).
     */
    private function mapContractNatureCode(?EmploymentContract $contract): string
    {
        if (! $contract) {
            return '01'; // Default: CDI
        }

        return match ($contract->contract_type) {
            'cdi' => '01',
            'cdd' => '02',
            'stage' => '03',
            'alternance' => '07',
            default => '01',
        };
    }
}
