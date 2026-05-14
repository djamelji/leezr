<?php

namespace App\Modules\Workforce\ReadModels;

use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\Services\DocumentTemplateResolver;
use Illuminate\Support\Collection;

class DocumentTemplateReadModel
{
    /**
     * Get all resolved templates for a company (company overrides + platform defaults).
     */
    public static function forCompany(int $companyId): Collection
    {
        return collect(DocumentTemplateResolver::allForCompany($companyId));
    }

    /**
     * Get a specific template by code (resolved: company > platform).
     */
    public static function byCode(string $code, int $companyId): ?DocumentTemplate
    {
        try {
            return DocumentTemplateResolver::resolve($companyId, $code);
        } catch (\DomainException) {
            return null;
        }
    }

    /**
     * Get templates filtered by subject type.
     */
    public static function bySubjectType(string $subjectType, int $companyId): Collection
    {
        return collect(DocumentTemplateResolver::allForCompany($companyId))
            ->filter(fn (DocumentTemplate $t) => $t->subject_type === $subjectType)
            ->values();
    }

    /**
     * Get all versions of a template (for version history).
     */
    public static function versionHistory(string $code, ?int $companyId): Collection
    {
        return DocumentTemplate::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->orderByDesc('version')
            ->get();
    }

    /**
     * Platform-only templates for admin management.
     */
    public static function platformTemplates(): Collection
    {
        return DocumentTemplate::platform()
            ->active()
            ->orderBy('code')
            ->get();
    }
}
