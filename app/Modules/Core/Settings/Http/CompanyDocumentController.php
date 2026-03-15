<?php

namespace App\Modules\Core\Settings\Http;

use App\Core\Documents\ReadModels\CompanyDocumentReadModel;
use App\Core\Documents\UseCases\DownloadCompanyDocumentUseCase;
use App\Core\Documents\UseCases\UploadCompanyDocumentData;
use App\Core\Documents\UseCases\UploadCompanyDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-174: Company document vault controller (passive).
 *
 * Maps HTTP to ReadModel/UseCases. Zero business logic.
 */
class CompanyDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            CompanyDocumentReadModel::get($company),
        );
    }

    public function upload(Request $request, string $code, UploadCompanyDocumentUseCase $useCase): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        $company = $request->attributes->get('company');

        $result = $useCase->execute(new UploadCompanyDocumentData(
            actor: $request->user(),
            company: $company,
            documentCode: $code,
            file: $request->file('file'),
        ));

        return response()->json([
            'message' => $result->replaced ? 'Document replaced.' : 'Document uploaded.',
            'document' => [
                'id' => $result->id,
                'code' => $result->code,
                'file_name' => $result->fileName,
                'file_size_bytes' => $result->fileSizeBytes,
                'uploaded_at' => $result->uploadedAt,
            ],
        ]);
    }

    public function download(Request $request, string $code, DownloadCompanyDocumentUseCase $useCase)
    {
        $company = $request->attributes->get('company');

        $result = $useCase->execute($request->user(), $company, $code);

        return Storage::disk($result->disk)->download($result->filePath, $result->fileName);
    }
}
