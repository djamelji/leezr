<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Audit\AuditAction;
use App\Core\Audit\AuditLogger;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Models\Membership;

/**
 * ADR-192: Batch-create document requests for all members of a role.
 *
 * Guards:
 * - DocumentTypeActivation must be enabled for the company
 * - Skips members with an active (requested/submitted) request for this doc type
 *
 * 1 batch = 1 audit entry (with count + role_id).
 */
class BatchRequestByRoleUseCase
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(int $companyId, int $companyRoleId, string $documentTypeCode): array
    {
        $docType = DocumentType::where('code', $documentTypeCode)
            ->where('scope', DocumentType::SCOPE_COMPANY_USER)
            ->active()
            ->firstOrFail();

        $activation = DocumentTypeActivation::where('company_id', $companyId)
            ->where('document_type_id', $docType->id)
            ->where('enabled', true)
            ->first();

        if (! $activation) {
            abort(422, 'Document type is not activated for this company.');
        }

        // Get all member user_ids for this role
        $memberUserIds = Membership::where('company_id', $companyId)
            ->where('company_role_id', $companyRoleId)
            ->pluck('user_id');

        if ($memberUserIds->isEmpty()) {
            return ['created' => 0, 'skipped' => 0];
        }

        // Find user_ids that already have an active request
        $existingActiveUserIds = DocumentRequest::where('company_id', $companyId)
            ->where('document_type_id', $docType->id)
            ->whereIn('user_id', $memberUserIds)
            ->whereIn('status', [DocumentRequest::STATUS_REQUESTED, DocumentRequest::STATUS_SUBMITTED])
            ->pluck('user_id')
            ->toArray();

        $eligibleUserIds = $memberUserIds->diff($existingActiveUserIds);
        $now = now();
        $created = 0;

        foreach ($eligibleUserIds as $userId) {
            DocumentRequest::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'document_type_id' => $docType->id,
                ],
                [
                    'status' => DocumentRequest::STATUS_REQUESTED,
                    'requested_at' => $now,
                    'submitted_at' => null,
                    'reviewed_at' => null,
                    'reviewer_id' => null,
                    'review_note' => null,
                ],
            );
            $created++;
        }

        if ($created > 0) {
            $this->audit->logCompany(
                $companyId,
                AuditAction::DOCUMENT_BATCH_REQUESTED,
                'document_request',
                null,
                ['metadata' => [
                    'document_type_code' => $documentTypeCode,
                    'company_role_id' => $companyRoleId,
                    'created_count' => $created,
                    'skipped_count' => count($existingActiveUserIds),
                ]],
            );
        }

        return [
            'created' => $created,
            'skipped' => count($existingActiveUserIds),
        ];
    }
}
