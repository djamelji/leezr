<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentTemplate;

class UpdateDocumentTemplateUseCase
{
    public function execute(
        DocumentTemplate $template,
        ?string $name = null,
        ?string $bodyHtml = null,
        ?string $headerHtml = null,
        ?string $footerHtml = null,
        ?string $description = null,
        ?array $variablesSchema = null,
        int $actorId = 0,
    ): DocumentTemplate {
        // Guard: system templates cannot be edited by company
        if ($template->is_system && $template->company_id !== null) {
            throw new \DomainException('Cannot modify a system template at company level.');
        }

        $bodyChanged = $bodyHtml !== null && $bodyHtml !== $template->body_html;

        // If body changes, create new version (deactivate old, create new)
        if ($bodyChanged) {
            $oldVersion = $template->version;

            // Deactivate current version
            $template->is_active = false;
            $template->save();

            // Create new version
            $newTemplate = DocumentTemplate::create([
                'company_id' => $template->company_id,
                'platform_template_id' => $template->platform_template_id,
                'code' => $template->code,
                'name' => $name ?? $template->name,
                'description' => $description ?? $template->description,
                'subject_type' => $template->subject_type,
                'body_html' => $bodyHtml,
                'header_html' => $headerHtml ?? $template->header_html,
                'footer_html' => $footerHtml ?? $template->footer_html,
                'variables_schema' => $variablesSchema ?? $template->variables_schema,
                'version' => $oldVersion + 1,
                'is_system' => $template->is_system,
                'is_active' => true,
                'paper_size' => $template->paper_size,
                'paper_orientation' => $template->paper_orientation,
            ]);

            // I13: Validate variables
            $missing = $newTemplate->validateVariables();
            if (! empty($missing)) {
                // Rollback: delete new, reactivate old
                $newTemplate->delete();
                $template->is_active = true;
                $template->save();
                throw new \DomainException('Template uses undefined variables: ' . implode(', ', $missing));
            }

            app(AuditLogger::class)->logCompany(
                companyId: $template->company_id ?? 0,
                action: 'document_template.versioned',
                targetType: 'document_template',
                targetId: (string) $newTemplate->id,
                options: [
                    'actorId' => $actorId,
                    'metadata' => [
                        'category' => 'workforce.documents',
                        'old_version' => $oldVersion,
                        'description' => "Template '{$template->code}' updated v{$oldVersion} → v{$newTemplate->version}",
                    ],
                ],
            );

            return $newTemplate;
        }

        // Non-body updates (name, description, header, footer, schema)
        if ($name !== null) $template->name = $name;
        if ($description !== null) $template->description = $description;
        if ($headerHtml !== null) $template->header_html = $headerHtml;
        if ($footerHtml !== null) $template->footer_html = $footerHtml;
        if ($variablesSchema !== null) $template->variables_schema = $variablesSchema;

        // I13: Revalidate
        $missing = $template->validateVariables();
        if (! empty($missing)) {
            throw new \DomainException('Template uses undefined variables: ' . implode(', ', $missing));
        }

        $template->save();

        app(AuditLogger::class)->logCompany(
            companyId: $template->company_id ?? 0,
            action: 'document_template.updated',
            targetType: 'document_template',
            targetId: (string) $template->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.documents',
                    'description' => "Template '{$template->code}' metadata updated (v{$template->version})",
                ],
            ],
        );

        return $template;
    }
}
