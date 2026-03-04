<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentRequest;

/**
 * ADR-192: Queue of active document requests for a company.
 *
 * Returns requests with status in (requested, submitted),
 * sorted by requested_at desc, with user/role/document_type eager-loaded.
 */
class DocumentRequestQueueReadModel
{
    public static function forCompany(int $companyId): array
    {
        return DocumentRequest::where('company_id', $companyId)
            ->whereIn('status', [
                DocumentRequest::STATUS_REQUESTED,
                DocumentRequest::STATUS_SUBMITTED,
            ])
            ->with([
                'user:id,first_name,last_name,email',
                'documentType:id,code,label,scope',
            ])
            ->orderByDesc('requested_at')
            ->get()
            ->map(function (DocumentRequest $req) {
                $membership = $req->user?->memberships()
                    ->where('company_id', $req->company_id)
                    ->with('companyRole:id,name,key')
                    ->first();

                return [
                    'id' => $req->id,
                    'status' => $req->status,
                    'user' => $req->user ? [
                        'id' => $req->user->id,
                        'first_name' => $req->user->first_name,
                        'last_name' => $req->user->last_name,
                        'email' => $req->user->email,
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
                    ] : null,
                    'requested_at' => $req->requested_at?->toISOString(),
                    'submitted_at' => $req->submitted_at?->toISOString(),
                ];
            })
            ->values()
            ->toArray();
    }
}
