<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves public storage files via PHP when Apache symlinks are blocked.
 *
 * ISPConfig uses SymLinksIfOwnerMatch which prevents Apache from following
 * the storage symlink. This controller bypasses that by reading files
 * directly from the public disk.
 *
 * ADR-401: Only serves files from storage/app/public (the public disk).
 * Path traversal is prevented by Storage::disk() sandboxing.
 */
class StorageFileController extends Controller
{
    public function __invoke(string $path): BinaryFileResponse
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        $fullPath = $disk->path($path);
        $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
