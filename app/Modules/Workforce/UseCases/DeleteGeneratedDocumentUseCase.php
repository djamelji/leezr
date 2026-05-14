<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\GeneratedDocument;
use Illuminate\Support\Facades\Storage;

class DeleteGeneratedDocumentUseCase
{
    public function execute(GeneratedDocument $doc, int $actorId): void
    {
        // I2: Signed documents cannot be deleted (boot() also enforces this)
        if ($doc->isSigned()) {
            throw new \DomainException('Cannot delete a signed document.');
        }

        $docId = $doc->id;
        $filePath = $doc->file_path;
        $templateCode = $doc->template?->code ?? 'unknown';

        // Delete file from storage
        $disk = config('documents.generated_disk', 'local');
        if ($filePath && Storage::disk($disk)->exists($filePath)) {
            Storage::disk($disk)->delete($filePath);
        }

        $doc->delete();

        app(AuditLogger::class)->logCompany(
            companyId: $doc->company_id,
            action: 'document.deleted',
            targetType: 'generated_document',
            targetId: (string) $docId,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.documents',
                    'description' => "Generated document #{$docId} deleted (template: {$templateCode})",
                ],
            ],
        );
    }
}
