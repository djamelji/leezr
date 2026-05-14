<?php

namespace App\Core\Documents\Services;

use App\Core\Documents\DocumentTemplate;

/**
 * Resolves the active template for a given company and code.
 *
 * Resolution order:
 * 1. Company-specific active template (company_id = X, code = Y, is_active = true)
 * 2. Platform active template (company_id IS NULL, code = Y, is_active = true)
 * 3. DomainException if neither found
 *
 * platform_template_id is for traceability only, NOT used in resolution.
 */
class DocumentTemplateResolver
{
    public static function resolve(int $companyId, string $code): DocumentTemplate
    {
        // 1. Company-specific active template
        $companyTemplate = DocumentTemplate::forCompany($companyId)
            ->byCode($code)
            ->active()
            ->orderByDesc('version')
            ->first();

        if ($companyTemplate) {
            return $companyTemplate;
        }

        // 2. Platform active template
        $platformTemplate = DocumentTemplate::platform()
            ->byCode($code)
            ->active()
            ->orderByDesc('version')
            ->first();

        if ($platformTemplate) {
            return $platformTemplate;
        }

        throw new \DomainException("No active template found for code '{$code}' (company #{$companyId} or platform).");
    }

    /**
     * List all available templates for a company (resolved: company overrides + platform defaults).
     */
    public static function allForCompany(int $companyId): array
    {
        // Get all active company templates
        $companyTemplates = DocumentTemplate::forCompany($companyId)
            ->active()
            ->orderByDesc('version')
            ->get()
            ->unique('code');

        $companyCodes = $companyTemplates->pluck('code')->toArray();

        // Get platform templates not overridden by company
        $platformTemplates = DocumentTemplate::platform()
            ->active()
            ->whereNotIn('code', $companyCodes)
            ->orderByDesc('version')
            ->get()
            ->unique('code');

        return $companyTemplates->merge($platformTemplates)
            ->sortBy('code')
            ->values()
            ->all();
    }
}
