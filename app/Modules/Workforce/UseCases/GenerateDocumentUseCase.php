<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentTemplate;
use App\Core\Documents\GeneratedDocument;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentPdfGenerator;
use App\Core\Documents\Services\DocumentTemplateResolver;
use App\Core\Documents\Services\DocumentVariableResolver;
use App\Core\Models\Company;
use App\Core\Modules\ModuleGate;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use Illuminate\Database\Eloquent\Model;

class GenerateDocumentUseCase
{
    // Subject types that ALWAYS create a MemberDocument in the vault
    const VAULT_SUBJECTS = ['employee', 'contract'];

    public function __construct(
        private DocumentPdfGenerator $pdfGenerator,
    ) {}

    public function execute(
        int $companyId,
        string $templateCode,
        string $subjectType,
        int $subjectId,
        int $actorId,
        bool $forceVault = false,
        ?array $extraMetadata = null,
    ): GeneratedDocument {
        // Guard: module enabled
        if (! ModuleGate::isEnabledGlobally('workforce_documents')) {
            throw new \DomainException('Module workforce_documents is not enabled for this company.');
        }

        // Guard: valid subject type
        if (! in_array($subjectType, DocumentTemplate::SUBJECT_TYPES, true)) {
            throw new \DomainException("Invalid subject type: {$subjectType}");
        }

        // Resolve template (company override > platform)
        $template = DocumentTemplateResolver::resolve($companyId, $templateCode);

        // Guard: template subject_type matches
        if ($template->subject_type !== $subjectType) {
            throw new \DomainException("Template '{$templateCode}' is for subject '{$template->subject_type}', not '{$subjectType}'.");
        }

        // Load subject with cross-company guard
        $subject = $this->loadSubject($subjectType, $subjectId, $companyId);
        $company = Company::findOrFail($companyId);

        // Resolve variables
        $variables = DocumentVariableResolver::resolve($subjectType, $subject, $company);

        // I13: Validate all template variables are resolved
        $usedVars = $template->extractUsedVariables();
        $missingVars = array_diff($usedVars, array_keys($variables));
        if (! empty($missingVars)) {
            throw new \DomainException('Cannot generate: missing variables: ' . implode(', ', $missingVars));
        }

        // Generate PDF
        $result = $this->pdfGenerator->generate($template, $variables, $companyId);

        // Build generation_snapshot
        $snapshot = array_merge($variables, [
            '_meta' => [
                'template_id' => $template->id,
                'template_code' => $template->code,
                'template_version' => $template->version,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'generated_at' => now()->toIso8601String(),
                'resolver_version' => DocumentVariableResolver::RESOLVER_VERSION,
            ],
        ]);

        // Build idempotency key
        $idempotencyKey = "doc:{$template->id}:{$subjectType}:{$subjectId}:{$template->version}";

        // Idempotency guard: return existing document if already generated
        $existing = GeneratedDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Create MemberDocument in vault for employee/contract subjects (or forced)
        $memberDocumentId = null;
        if (in_array($subjectType, self::VAULT_SUBJECTS, true) || $forceVault) {
            $memberDocumentId = $this->createMemberDocument($subjectType, $subject, $companyId, $result);

            // If vault was forced but creation failed (e.g. no resolvable user_id), abort
            if ($forceVault && $memberDocumentId === null) {
                throw new \DomainException(
                    "Cannot create vault entry for {$subjectType}#{$subjectId}: no resolvable user_id."
                );
            }
        }

        // Create GeneratedDocument
        $doc = GeneratedDocument::create([
            'company_id' => $companyId,
            'document_template_id' => $template->id,
            'template_version' => $template->version,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'generated_by' => $actorId,
            'generated_at' => now(),
            'file_path' => $result['file_path'],
            'file_size_bytes' => $result['file_size_bytes'],
            'generation_snapshot' => $snapshot,
            'idempotency_key' => $idempotencyKey,
            'member_document_id' => $memberDocumentId,
            'metadata' => $extraMetadata,
        ]);

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'document.generated',
            targetType: 'generated_document',
            targetId: (string) $doc->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.documents',
                    'template_code' => $templateCode,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                ],
            ],
        );

        return $doc;
    }

    private function loadSubject(string $subjectType, int $subjectId, int $companyId): Model
    {
        $modelClass = match ($subjectType) {
            'employee' => \App\Core\Workforce\Employee::class,
            'contract' => \App\Core\Workforce\EmploymentContract::class,
            'leave_request' => \App\Core\Workforce\LeaveRequest::class,
            'payroll_run' => \App\Core\Workforce\PayrollRun::class,
            'payroll_line' => \App\Core\Workforce\PayrollLine::class,
        };

        $subject = $modelClass::withoutGlobalScopes()->where('id', $subjectId)->first();

        if (! $subject) {
            throw new \DomainException("Subject {$subjectType}#{$subjectId} not found.");
        }

        // Cross-company guard (I10)
        if ($subject->company_id !== $companyId) {
            throw new \DomainException("Subject {$subjectType}#{$subjectId} does not belong to company #{$companyId}.");
        }

        return $subject;
    }

    private function createMemberDocument(string $subjectType, Model $subject, int $companyId, array $fileResult): ?int
    {
        // Resolve user_id from subject
        $userId = null;

        if ($subjectType === 'employee') {
            $userId = $subject->user_id;
        } elseif ($subjectType === 'contract') {
            $userId = $subject->employee?->user_id;
        } elseif ($subjectType === 'payroll_line') {
            $userId = $subject->employee?->user_id;
        }

        if (! $userId) {
            return null;
        }

        // Create a MemberDocument entry (generated doc in vault)
        // Using direct create since we don't need the full upload pipeline
        $memberDoc = MemberDocument::create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'document_type_id' => null, // Generated docs don't have a DocumentType
            'file_path' => $fileResult['file_path'],
            'file_name' => basename($fileResult['file_path']),
            'file_size_bytes' => $fileResult['file_size_bytes'],
            'mime_type' => 'application/pdf',
        ]);

        return $memberDoc->id;
    }
}
