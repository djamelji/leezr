<?php

namespace App\Core\Workforce\Dsn\Gateway;

use Illuminate\Support\Facades\Storage;

/**
 * File-based DSN gateway — copies the DSN file to a "transmitted" directory.
 *
 * Simulates transmission by writing a copy to a configurable disk/path.
 * Useful for staging environments or local testing of the full pipeline.
 *
 * Sprint 6.5 — ADR-523
 */
class FileDsnGateway implements DsnGatewayInterface
{
    public function __construct(
        private string $disk = 'local',
        private string $targetDir = 'workforce/dsn/transmitted',
    ) {}

    public function submit(string $filePath, array $metadata): DsnSubmissionResult
    {
        $sourceDisk = Storage::disk($this->disk);

        if (! $sourceDisk->exists($filePath)) {
            return DsnSubmissionResult::failure(
                "DSN file not found: {$filePath}"
            );
        }

        $content = $sourceDisk->get($filePath);
        $filename = basename($filePath);
        $targetPath = $this->targetDir . '/' . $filename;

        $sourceDisk->put($targetPath, $content);

        $reference = 'FILE-' . ($metadata['company_id'] ?? '0')
            . '-' . ($metadata['period_month'] ?? 'unknown')
            . '-' . time();

        return DsnSubmissionResult::success($reference, [
            'target_path' => $targetPath,
        ]);
    }
}
