<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\MemberDocument;

/**
 * ADR-192: Queue of active document requests for a company.
 *
 * Returns requests with status in (requested, submitted),
 * sorted by requested_at desc, with user/role/document_type eager-loaded.
 *
 * ADR-406: Enriched with upload data (file_name, mime_type, etc.)
 * so the frontend can preview submitted documents before approval.
 */
class DocumentRequestQueueReadModel
{
    public static function forCompany(int $companyId): array
    {
        $requests = DocumentRequest::where('company_id', $companyId)
            ->whereIn('status', [
                DocumentRequest::STATUS_REQUESTED,
                DocumentRequest::STATUS_SUBMITTED,
            ])
            ->with([
                'user:id,first_name,last_name,email',
                'documentType',
            ])
            ->orderByDesc('requested_at')
            ->get();

        // Batch-load member documents for submitted requests (1 query)
        $submittedUserDocPairs = $requests
            ->where('status', DocumentRequest::STATUS_SUBMITTED)
            ->map(fn ($r) => ['user_id' => $r->user_id, 'document_type_id' => $r->document_type_id])
            ->values();

        $uploads = collect();
        if ($submittedUserDocPairs->isNotEmpty()) {
            $uploads = MemberDocument::where('company_id', $companyId)
                ->where(function ($q) use ($submittedUserDocPairs) {
                    foreach ($submittedUserDocPairs as $pair) {
                        $q->orWhere(fn ($sub) => $sub
                            ->where('user_id', $pair['user_id'])
                            ->where('document_type_id', $pair['document_type_id']));
                    }
                })
                ->get()
                ->keyBy(fn ($d) => $d->user_id.':'.$d->document_type_id);
        }

        return $requests->map(function (DocumentRequest $req) use ($uploads) {
            $membership = $req->user?->memberships()
                ->where('company_id', $req->company_id)
                ->with('companyRole:id,name,key')
                ->first();

            $uploadKey = $req->user_id.':'.$req->document_type_id;
            $memberDoc = $uploads->get($uploadKey);

            return [
                'id' => $req->id,
                'status' => $req->status,
                'user' => $req->user ? [
                    'id' => $req->user->id,
                    'first_name' => $req->user->first_name,
                    'last_name' => $req->user->last_name,
                    'email' => $req->user->email,
                    'membership_id' => $membership?->id,
                ] : null,
                'role' => $membership?->companyRole ? [
                    'id' => $membership->companyRole->id,
                    'key' => $membership->companyRole->key,
                    'name' => $membership->companyRole->name,
                ] : null,
                'document_type' => $req->documentType ? [
                    'id' => $req->documentType->id,
                    'code' => $req->documentType->code,
                    'label' => $req->documentType->label,
                    'requires_expiration' => (bool) $req->documentType->requires_expiration,
                    'max_file_size_mb' => $req->documentType->validation_rules['max_file_size_mb'] ?? 10,
                    'accepted_types' => $req->documentType->validation_rules['accepted_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'],
                ] : null,
                'upload' => $memberDoc ? [
                    'file_name' => $memberDoc->file_name,
                    'file_size_bytes' => $memberDoc->file_size_bytes,
                    'mime_type' => $memberDoc->mime_type,
                    'expires_at' => $memberDoc->expires_at?->toIso8601String(),
                    'ocr_text' => $memberDoc->ocr_text,
                    'ai_analysis' => $memberDoc->ai_analysis,
                    'ai_insights' => $memberDoc->ai_insights,
                    'ai_status' => $memberDoc->ai_status,
                ] : null,
                'requested_at' => $req->requested_at?->toISOString(),
                'submitted_at' => $req->submitted_at?->toISOString(),
            ];
        })
            ->values()
            ->toArray();
    }
}
