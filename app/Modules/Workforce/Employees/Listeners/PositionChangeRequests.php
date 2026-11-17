<?php

namespace App\Modules\Workforce\Employees\Listeners;

use App\Core\Documents\Services\RequiredDocumentRequests;
use App\Core\Models\Company;
use App\Core\Requirements\RequirementAuthority;
use App\Core\Workforce\Employee;

/**
 * ADR-818 (chantier C) — a CHANGE OF POSITION is a business event : for companies computing requirements by
 * position, the documents the new position requires (and not yet held or requested) become requests. Nothing
 * is cancelled or regenerated for the previous position. Preparatory records (hire not finalised) and
 * terminated employees are skipped ; role-authority companies are untouched (historical behaviour).
 */
final class PositionChangeRequests
{
    public static function register(): void
    {
        Employee::updated(function (Employee $employee) {
            if (! $employee->wasChanged('job_role_id') || $employee->job_role_id === null) {
                return;
            }
            if ($employee->isPreparatory() || $employee->status === Employee::STATUS_TERMINATED || ! RequirementAuthority::isPosition((int) $employee->company_id)) {
                return;
            }
            $company = Company::find($employee->company_id);
            $actor = auth()->user();
            $company && RequiredDocumentRequests::ensureForEmployee($company, $employee, $actor instanceof \App\Core\Models\User ? $actor : null);
        });
    }
}
