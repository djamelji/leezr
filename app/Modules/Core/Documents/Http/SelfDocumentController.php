<?php

namespace App\Modules\Core\Documents\Http;

use App\Core\Documents\ReadModels\SelfDocumentReadModel;
use App\Modules\Core\Members\UseCases\DownloadOwnDocumentUseCase;
use App\Modules\Core\Members\UseCases\UploadOwnDocumentData;
use App\Modules\Core\Members\UseCases\UploadOwnDocumentUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-173: Self-document controller (passive).
 *
 * Maps HTTP to ReadModel/UseCases. Zero business logic.
 */
class SelfDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');

        return response()->json(
            SelfDocumentReadModel::get($request->user(), $company),
        );
    }

    public function upload(Request $request, string $code, UploadOwnDocumentUseCase $useCase): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $company = $request->attributes->get('company');

        $result = $useCase->execute(new UploadOwnDocumentData(
            user: $request->user(),
            company: $company,
            documentCode: $code,
            file: $request->file('file'),
            expiresAt: $request->input('expires_at'),
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

    public function download(Request $request, string $code, DownloadOwnDocumentUseCase $useCase)
    {
        $company = $request->attributes->get('company');

        $result = $useCase->execute($request->user(), $company, $code);

        return Storage::disk($result->disk)->download($result->filePath, $result->fileName);
    }
}
