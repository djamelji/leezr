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
        // ADR-759 (M2) : inclut aussi les demandes refusées / à remplacer / en vérification
        // (le centre de suivi doit les montrer), pas seulement requested/submitted.
        $requests = DocumentRequest::where('company_id', $companyId)
            ->whereIn('status', [
                DocumentRequest::STATUS_REQUESTED,
                DocumentRequest::STATUS_SUBMITTED,
                DocumentRequest::STATUS_IN_REVIEW,
                DocumentRequest::STATUS_REJECTED,
                DocumentRequest::STATUS_TO_REPLACE,
            ])
            ->with([
                'user:id,first_name,last_name,email',
                'documentType',
                'reviewer:id,first_name,last_name',
            ])
            ->orderByDesc('requested_at')
            ->get();

        // ADR-760 (M3) : aperçu keyé par SUJET (document_subject_type/id) → couvre aussi les
        // demandes d'un Employee SANS compte (user_id null).
        $subjectPairs = $requests
            ->whereIn('status', [DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_IN_REVIEW, DocumentRequest::STATUS_TO_REPLACE])
            ->map(fn ($r) => [$r->document_subject_type, $r->document_subject_id, $r->document_type_id])
            ->values();

        $uploads = collect();
        if ($subjectPairs->isNotEmpty()) {
            $uploads = MemberDocument::where('company_id', $companyId)
                ->where(function ($q) use ($subjectPairs) {
                    foreach ($subjectPairs as [$st, $sid, $tid]) {
                        $q->orWhere(fn ($sub) => $sub
                            ->where('document_subject_type', $st)
                            ->where('document_subject_id', $sid)
                            ->where('document_type_id', $tid));
                    }
                })
                ->get()
                ->keyBy(fn ($d) => $d->document_subject_type.':'.$d->document_subject_id.':'.$d->document_type_id);
        }

        // Libellés des employés sans compte (pour afficher une personne sur ces lignes).
        $employeeIds = $requests->where('document_subject_type', 'employee')->pluck('document_subject_id')->filter()->unique()->all();
        $employees = $employeeIds
            ? \App\Core\Workforce\Employee::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $employeeIds)->get()->keyBy('id')
            : collect();

        return $requests->map(function (DocumentRequest $req) use ($uploads, $employees) {
            $membership = $req->user?->memberships()
                ->where('company_id', $req->company_id)
                ->with('companyRole:id,name,key')
                ->first();

            $uploadKey = $req->document_subject_type.':'.$req->document_subject_id.':'.$req->document_type_id;
            $memberDoc = $uploads->get($uploadKey);

            // Personne : compte si présent, sinon l'employé sans compte (libellé uniquement).
            $employee = $req->document_subject_type === 'employee' ? $employees->get($req->document_subject_id) : null;

            return [
                'id' => $req->id,
                'status' => $req->status,
                'subject' => $req->document_subject_type.':'.$req->document_subject_id,
                'person_ref' => $req->document_subject_type.'-'.$req->document_subject_id,
                'user' => $req->user ? [
                    'id' => $req->user->id,
                    'first_name' => $req->user->first_name,
                    'last_name' => $req->user->last_name,
                    'email' => $req->user->email,
                    'membership_id' => $membership?->id,
                ] : ($employee ? [
                    'id' => null,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'email' => $employee->email,
                    'membership_id' => null,
                    'account_less' => true,
                ] : null),
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
                    'ai_suggestions' => $memberDoc->ai_suggestions,
                    'ai_status' => $memberDoc->ai_status,
                ] : null,
                'requested_at' => $req->requested_at?->toISOString(),
                'submitted_at' => $req->submitted_at?->toISOString(),
                // ADR-759 (M2) : échéance, message, décision, traçabilité.
                'message' => $req->message,
                'due_date' => $req->due_date?->toDateString(),
                'is_overdue' => $req->isOverdue(),
                'review_note' => $req->review_note,
                'reviewed_at' => $req->reviewed_at?->toISOString(),
                'reviewer' => $req->reviewer ? trim($req->reviewer->first_name.' '.$req->reviewer->last_name) : null,
                'requires_validation' => (bool) $req->requires_validation,
            ];
        })
            ->values()
            ->toArray();
    }
}
