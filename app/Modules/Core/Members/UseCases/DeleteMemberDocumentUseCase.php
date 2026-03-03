<?php

namespace App\Modules\Core\Members\UseCases;

use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentType;
use App\Core\Documents\MemberDocument;
use App\Core\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-180: Delete a member document and reset the workflow request.
 *
 * Pipeline: resolve membership → load type → load document → delete file → delete record → reset request.
 */
class DeleteMemberDocumentUseCase
{
    public function execute(DeleteMemberDocumentData $data): void
    {
        // 1. Resolve membership
        $membership = $data->company->memberships()->findOrFail($data->membershipId);
        $user = User::findOrFail($membership->user_id);

        // 2. Load type
        $type = DocumentType::where('code', $data->documentCode)->firstOrFail();

        // 3. Load document
        $document = MemberDocument::where('company_id', $data->company->id)
            ->where('user_id', $user->id)
            ->where('document_type_id', $type->id)
            ->firstOrFail();

        // 4. Delete file from storage
        Storage::disk('local')->delete($document->file_path);

        // 5. Delete record
        $document->delete();

        // 6. Reset workflow request if exists
        $request = DocumentRequest::where('company_id', $data->company->id)
            ->where('user_id', $user->id)
            ->where('document_type_id', $type->id)
            ->whereIn('status', [DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_APPROVED, DocumentRequest::STATUS_REJECTED])
            ->first();

        if ($request) {
            $request->update([
                'status' => DocumentRequest::STATUS_REQUESTED,
                'reviewer_id' => null,
                'review_note' => null,
                'reviewed_at' => null,
                'submitted_at' => null,
            ]);
        }
    }
}
