<?php

use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Jobdomains\JobdomainGate;
use App\Core\Models\Company;
use Illuminate\Database\Migrations\Migration;

/**
 * ADR-389: Backfill required_override for existing companies.
 *
 * With the removal of required_by_jobdomains from DocumentTypeCatalog,
 * the obligation is now expressed via required_override on the activation.
 * This migration compensates by setting required_override=true for documents
 * that the jobdomain preset marks as required.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = Company::whereNotNull('jobdomain_key')->get();

        foreach ($companies as $company) {
            $defaultDocs = JobdomainGate::defaultDocumentsFor($company->jobdomain_key);
            $requiredCodes = collect($defaultDocs)
                ->where('required', true)
                ->pluck('code')
                ->toArray();

            if (empty($requiredCodes)) {
                continue;
            }

            $typeIds = DocumentType::whereIn('code', $requiredCodes)->pluck('id');

            DocumentTypeActivation::where('company_id', $company->id)
                ->whereIn('document_type_id', $typeIds)
                ->where('required_override', false)
                ->update(['required_override' => true]);
        }
    }

    public function down(): void
    {
        // Not reversible — required_override was already false before, setting it back
        // would remove legitimate company-level overrides set by admins
    }
};
