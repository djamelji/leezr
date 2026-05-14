<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\GeneratedDocument;

class UpdateSignatureStatusUseCase
{
    public function execute(
        GeneratedDocument $doc,
        string $newStatus,
        int $actorId,
        ?string $signatureProvider = null,
        ?array $signatureMetadata = null,
    ): GeneratedDocument {
        // I14: Signature transitions strictly controlled
        $doc->transitionSignatureTo($newStatus);

        if ($signatureProvider !== null) {
            $doc->signature_provider = $signatureProvider;
        }

        if ($signatureMetadata !== null) {
            $doc->signature_metadata = $signatureMetadata;
        }

        if ($newStatus === GeneratedDocument::SIGNATURE_SIGNED) {
            $doc->signed_at = now();
        }

        $doc->save();

        app(AuditLogger::class)->logCompany(
            companyId: $doc->company_id,
            action: 'document.signature_updated',
            targetType: 'generated_document',
            targetId: (string) $doc->id,
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.documents',
                    'new_status' => $newStatus,
                    'provider' => $signatureProvider,
                    'description' => "Signature status changed to '{$newStatus}'",
                ],
            ],
        );

        return $doc;
    }
}
