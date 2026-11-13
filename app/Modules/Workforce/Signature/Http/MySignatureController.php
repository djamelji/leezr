<?php

namespace App\Modules\Workforce\Signature\Http;

use App\Modules\Workforce\Signature\Models\SignatureRequest;
use App\Modules\Workforce\Signature\ReadModels\MySignatureReadModel;
use App\Modules\Workforce\Signature\UseCases\OpenMySignatureUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ADR-769 (M7 LOT 3) — « Mes signatures » (self-service : ownership of the signer row, no permission). */
class MySignatureController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['signatures' => MySignatureReadModel::list($request->attributes->get('company'), $request->user())]);
    }

    public function open(Request $request, int $id, OpenMySignatureUseCase $open): JsonResponse
    {
        return response()->json(['url' => $open->execute($request->attributes->get('company'), $request->user(), $id)]);
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $company = $request->attributes->get('company');
        $signer = MySignatureReadModel::signersOf($company, $request->user())->findOrFail($id);
        $req = SignatureRequest::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($signer->signature_request_id);

        abort_unless($req->status === SignatureRequest::STATUS_COMPLETED && $req->signed_document_path, 404, 'No signed document available.');
        $disk = config('documents.generated_disk', 'local');
        abort_unless(Storage::disk($disk)->exists($req->signed_document_path), 404, 'Signed document file not found.');

        return Storage::disk($disk)->download($req->signed_document_path, 'signed-'.$req->id.'.pdf');
    }
}
