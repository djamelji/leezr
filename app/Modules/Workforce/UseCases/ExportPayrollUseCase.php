<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Workforce\PayrollRun;

class ExportPayrollUseCase
{
    public function execute(PayrollRun $run, int $actorId, string $exportFormat = 'json'): PayrollRun
    {
        // Guard: status must be validated
        if ($run->status !== PayrollRun::STATUS_VALIDATED) {
            throw new \DomainException("Cannot export PayrollRun: status is '{$run->status}', expected 'validated'.");
        }

        // Build export_payload for each line
        $lines = $run->lines()->with('employee')->get();
        foreach ($lines as $line) {
            $line->export_payload = $line->buildExportPayload();
            // Bypass boot immutability: parent is still validated at this point,
            // so we use saveQuietly via direct update
            \Illuminate\Support\Facades\DB::table('workforce_payroll_lines')
                ->where('id', $line->id)
                ->update(['export_payload' => json_encode($line->export_payload)]);
        }

        // Build exported_snapshot
        $exportedSnapshot = [
            'format' => $exportFormat,
            'exported_at' => now()->toIso8601String(),
            'employee_count' => $run->employee_count,
            'total_gross_cents' => $run->total_gross_cents,
            'lines' => $lines->map(fn ($l) => [
                'employee_id' => $l->employee_id,
                'gross_basis_cents' => $l->gross_basis_cents,
            ])->toArray(),
        ];

        $run->transitionTo(PayrollRun::STATUS_EXPORTED);
        $run->exported_by = $actorId;
        $run->exported_at = now();
        $run->export_format = $exportFormat;
        $run->exported_snapshot = $exportedSnapshot;
        $run->save();

        app(AuditLogger::class)->logCompany(
            companyId: $run->company_id,
            action: 'payroll_run.exported',
            targetType: 'payroll_run',
            targetId: (string) $run->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.payroll',
                    'export_format' => $exportFormat,
                    'employee_count' => $run->employee_count,
                    'description' => "PayrollRun exported in format '{$exportFormat}'",
                ],
            ],
        );

        return $run;
    }
}
