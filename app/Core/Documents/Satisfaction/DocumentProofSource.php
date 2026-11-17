<?php

namespace App\Core\Documents\Satisfaction;

use App\Core\Models\Company;

/**
 * ADR-819 (Company 2.0) — a MODULE-provided way to satisfy a document requirement without an upload.
 *
 * A source never copies a file and never creates a document : it only answers « for these employees,
 * which document type codes are already proven by a finalised artefact I own ? ». The core registry
 * decides nothing about business rules ; each source enforces its own conditions (same company, right
 * person, finalised status, latest version, module availability) and returns a traceable reference.
 */
interface DocumentProofSource
{
    /** @return list<string> document type codes this source can prove */
    public function documentTypeCodes(): array;

    /**
     * @param  list<int>  $employeeIds  employees of $company only
     * @return array<int, array<string, array{source:string, reference:string, label:string, at:?string}>> employeeId → code → proof
     */
    public function proofsFor(Company $company, array $employeeIds): array;
}
