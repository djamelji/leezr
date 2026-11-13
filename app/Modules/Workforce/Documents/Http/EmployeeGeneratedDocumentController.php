<?php

namespace App\Modules\Workforce\Documents\Http;

use App\Core\Documents\GeneratedDocument;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Documents\Security\GeneratedDocumentAccess;
use App\Modules\Workforce\ReadModels\GeneratedDocumentReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-771 — the generated documents of ONE person, filed in her file BY REFERENCE (no copy, no
 * upload) : contract, attestations, leave documents. The signed version is served at the same place
 * once a signature is really finalised ; the original is never replaced.
 */
class EmployeeGeneratedDocumentController
{
    /** Management view : the file of a collaborator (scope enforced by the route middleware). */
    public function byEmployee(Request $request, int $employeeId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('company_id');
        Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($employeeId);

        return response()->json(['documents' => GeneratedDocumentReadModel::fileOf(
            $companyId, $employeeId, fn (GeneratedDocument $d) => GeneratedDocumentAccess::canOpen($request, $d),
        )]);
    }

    /** Management view : signed version of a document (rights checked by the route middleware). */
    public function signed(Request $request, int $id): StreamedResponse
    {
        $document = GeneratedDocument::withoutGlobalScopes()->where('company_id', $request->attributes->get('company_id'))->findOrFail($id);

        return $this->stream($document, true);
    }

    /** Self-service : MY generated document, original or signed (ownership, no permission). */
    public function downloadOwn(Request $request, int $id): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('company_id');
        $employeeId = Employee::withoutGlobalScopes()->where('company_id', $companyId)->where('user_id', $request->user()->id)->value('id');
        $document = $employeeId
            ? GeneratedDocumentReadModel::forEmployee((int) $employeeId, $companyId)->firstWhere('id', $id)
            : null;
        abort_if($document === null, 404);

        return $this->stream($document, $request->query('version') === 'signed');
    }

    private function stream(GeneratedDocument $document, bool $signed): StreamedResponse
    {
        $disk = Storage::disk(config('documents.generated_disk', 'local'));
        $path = $signed ? GeneratedDocumentAccess::signedRequest($document)?->signed_document_path : $document->file_path;
        abort_unless($path && $disk->exists($path), 404, $signed ? 'No signed version available.' : 'Document file not found.');

        return $disk->download($path, ($signed ? 'signed-' : '').basename($document->file_path));
    }
}
