<?php

namespace App\Modules\Platform\Email\Http;

use App\Core\Email\EmailAttachment;
use App\Core\Email\EmailAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentController
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimetypes:'.implode(',', EmailAttachmentService::allowedMimes()),
        ]);

        $attachment = app(EmailAttachmentService::class)->upload($request->file('file'));

        return response()->json([
            'id' => $attachment->id,
            'original_filename' => $attachment->original_filename,
            'human_size' => $attachment->human_size,
            'mime_type' => $attachment->mime_type,
        ]);
    }

    public function download(int $id): StreamedResponse
    {
        $attachment = EmailAttachment::findOrFail($id);

        return app(EmailAttachmentService::class)->serveDownload($attachment);
    }
}
