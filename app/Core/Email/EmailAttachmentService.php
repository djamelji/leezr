<?php

namespace App\Core\Email;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    private const MAX_FILES = 5;

    private const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/zip',
        'text/plain',
        'text/csv',
    ];

    /**
     * Upload a file to temporary storage (before associating with a log).
     */
    public function upload(UploadedFile $file): EmailAttachment
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $uuid = Str::uuid();
        $filename = $uuid.'_'.$file->getClientOriginalName();
        $path = "email-attachments/{$year}/{$month}/{$filename}";

        Storage::disk('local')->putFileAs(
            "email-attachments/{$year}/{$month}",
            $file,
            $filename,
        );

        return EmailAttachment::create([
            'email_log_id' => null, // temporary — will be linked on send
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'disk' => 'local',
            'path' => $path,
            'created_at' => now(),
        ]);
    }

    /**
     * Attach uploaded files to an email log.
     */
    public function attach(EmailLog $log, array $attachmentIds): void
    {
        if (empty($attachmentIds)) {
            return;
        }

        EmailAttachment::whereIn('id', $attachmentIds)
            ->whereNull('email_log_id')
            ->update(['email_log_id' => $log->id]);
    }

    /**
     * Serve a file download.
     */
    public function serveDownload(EmailAttachment $attachment): StreamedResponse
    {
        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_filename,
        );
    }

    /**
     * Store an attachment from IMAP-fetched raw content.
     */
    public function storeFromImap(EmailLog $log, string $filename, string $content, string $mimeType): EmailAttachment
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $uuid = Str::uuid();
        $storedFilename = $uuid.'_'.$filename;
        $path = "email-attachments/{$year}/{$month}/{$storedFilename}";

        Storage::disk('local')->put($path, $content);

        return EmailAttachment::create([
            'email_log_id' => $log->id,
            'filename' => $storedFilename,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($content),
            'disk' => 'local',
            'path' => $path,
            'created_at' => now(),
        ]);
    }

    public static function maxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    public static function maxFiles(): int
    {
        return self::MAX_FILES;
    }

    public static function allowedMimes(): array
    {
        return self::ALLOWED_MIMES;
    }
}
