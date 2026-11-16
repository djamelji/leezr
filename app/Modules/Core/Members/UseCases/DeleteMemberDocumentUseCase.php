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

        // 4+5+6. ADR-810.1 : la ligne du coffre et l'état de la demande changent ENSEMBLE ; le fichier n'est
        // effacé qu'après validation de la transaction (un échec ne laisse jamais une demande « transmise »
        // pointant vers un fichier déjà supprimé). Une demande en vérification revient aussi à « demandée ».
        $filePath = $document->file_path;
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $user, $type, $document) {
            $document->delete();

            $request = DocumentRequest::where('company_id', $data->company->id)
                ->where('user_id', $user->id)
                ->where('document_type_id', $type->id)
                ->whereIn('status', [DocumentRequest::STATUS_SUBMITTED, DocumentRequest::STATUS_IN_REVIEW, DocumentRequest::STATUS_APPROVED, DocumentRequest::STATUS_REJECTED])
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
        });

        if ($filePath) {
            Storage::disk('local')->delete($filePath);
        }
    }
}
