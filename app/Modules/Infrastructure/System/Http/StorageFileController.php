<?php

namespace App\Modules\Infrastructure\System\Http;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves public storage files via PHP (ADR-401).
 *
 * ISPConfig blocks /storage/ at Apache level (vhost config).
 * Files are served via /media/{path} route instead, reading directly
 * from the public disk. Path traversal is prevented by Storage::disk() sandboxing.
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
