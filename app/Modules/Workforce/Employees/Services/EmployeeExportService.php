<?php

namespace App\Modules\Workforce\Employees\Services;

use App\Modules\Workforce\ReadModels\EmployeeReadModel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W-G2 (ADR-642) — export CSV des collaborateurs. Respecte la Company, les filtres actifs, le
 * périmètre de visibilité (scope manager via `visibleIds`) et les permissions.
 *
 * Sécurité des données : l'export n'inclut JAMAIS de données sensibles (salaires, IBAN, numéros
 * nationaux). Le masquage W-D s'applique donc de fait (aucune colonne de rémunération). Borné.
 */
class EmployeeExportService
{
    public const MAX_ROWS = 10_000;

    public const COLUMNS = ['employee_number', 'first_name', 'last_name', 'email', 'phone', 'status', 'hire_date', 'department', 'job_role', 'manager', 'contract_type'];

    /** @param array<string,mixed> $filters  search/status/department_id/job_role_id/manager_id/contract_type */
    public function streamCsv(int $companyId, array $filters, ?array $visibleIds): StreamedResponse
    {
        $query = EmployeeReadModel::filteredQuery(
            companyId: $companyId,
            search: $filters['search'] ?? null,
            status: $filters['status'] ?? null,
            departmentId: $filters['department_id'] ?? null,
            jobRoleId: $filters['job_role_id'] ?? null,
            managerId: $filters['manager_id'] ?? null,
            contractType: $filters['contract_type'] ?? null,
            visibleIds: $visibleIds,
        )->with(['department:id,name', 'jobRole:id,title', 'manager:id,first_name,last_name', 'currentContract:id,employee_id,contract_type'])
            ->orderBy('last_name')
            ->limit(self::MAX_ROWS);

        return new StreamedResponse(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel-friendly)
            fputcsv($out, self::COLUMNS);

            $query->chunk(500, function ($employees) use ($out) {
                foreach ($employees as $e) {
                    fputcsv($out, [
                        $e->employee_number,
                        $e->first_name,
                        $e->last_name,
                        $e->email,
                        $e->phone,
                        $e->status,
                        optional($e->hire_date)->toDateString(),
                        $e->department?->name,
                        $e->jobRole?->title,
                        $e->manager ? trim($e->manager->first_name.' '.$e->manager->last_name) : null,
                        $e->currentContract?->contract_type,
                    ]);
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="collaborateurs.csv"',
        ]);
    }

    /** Modèle CSV d'import téléchargeable (en-têtes attendus + une ligne d'exemple). */
    public function templateCsv(): StreamedResponse
    {
        return new StreamedResponse(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['first_name', 'last_name', 'employee_number', 'email', 'phone', 'hire_date', 'status', 'department', 'job_role', 'manager']);
            fputcsv($out, ['Marie', 'Durand', 'EMP-100', 'marie.durand@example.com', '+33600000000', '2026-01-15', 'active', 'Logistique', 'Préparateur', 'EMP-001']);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-import-collaborateurs.csv"',
        ]);
    }
}
