<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentTemplate;

class CreateDocumentTemplateUseCase
{
    public function execute(
        ?int $companyId,
        string $code,
        string $name,
        string $subjectType,
        string $bodyHtml,
        array $variablesSchema,
        ?string $headerHtml = null,
        ?string $footerHtml = null,
        ?string $description = null,
        ?int $platformTemplateId = null,
        int $actorId = 0,
    ): DocumentTemplate {
        // Guard: valid subject type
        if (! in_array($subjectType, DocumentTemplate::SUBJECT_TYPES, true)) {
            throw new \DomainException("Invalid subject type: {$subjectType}. Allowed: " . implode(', ', DocumentTemplate::SUBJECT_TYPES));
        }

        // Guard: no duplicate active version for this (company, code)
        $existing = DocumentTemplate::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            throw new \DomainException("An active template already exists for code '{$code}'" . ($companyId ? " in company #{$companyId}" : ' at platform level') . '.');
        }

        $template = DocumentTemplate::create([
            'company_id' => $companyId,
            'platform_template_id' => $platformTemplateId,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'subject_type' => $subjectType,
            'body_html' => $bodyHtml,
            'header_html' => $headerHtml,
            'footer_html' => $footerHtml,
            'variables_schema' => $variablesSchema,
            'version' => 1,
            'is_system' => $companyId === null,
            'is_active' => true,
        ]);

        // I13: Validate all used variables exist in schema
        $missing = $template->validateVariables();
        if (! empty($missing)) {
            $template->delete();
            throw new \DomainException('Template uses undefined variables: ' . implode(', ', $missing));
        }

        app(AuditLogger::class)->logCompany(
            companyId: $companyId ?? 0,
            action: 'document_template.created',
            targetType: 'document_template',
            targetId: (string) $template->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.documents',
                    'subject_type' => $subjectType,
                    'description' => "Document template '{$code}' v1 created",
                ],
            ],
        );

        return $template;
    }
}
