<?php

namespace App\Modules\Workforce\UseCases;

use App\Core\Audit\AuditLogger;
use App\Core\Documents\GeneratedDocument;
use App\Core\Modules\ModuleGate;
use Illuminate\Support\Facades\DB;

class BulkGenerateDocumentUseCase
{
    const MAX_BULK_SIZE = 100;

    public function __construct(
        private GenerateDocumentUseCase $generateUseCase,
    ) {}

    /**
     * @return GeneratedDocument[]
     */
    public function execute(
        int $companyId,
        string $templateCode,
        string $subjectType,
        array $subjectIds,
        int $actorId,
    ): array {
        // Guard: module enabled
        if (! ModuleGate::isEnabledGlobally('workforce_documents')) {
            throw new \DomainException('Module workforce_documents is not enabled for this company.');
        }

        // Guard: bulk limit
        if (count($subjectIds) > self::MAX_BULK_SIZE) {
            throw new \DomainException('Bulk generation limited to ' . self::MAX_BULK_SIZE . ' documents. Received: ' . count($subjectIds));
        }

        if (empty($subjectIds)) {
            throw new \DomainException('No subject IDs provided.');
        }

        $documents = [];

        // All-or-nothing: DB::transaction wraps the entire batch
        DB::transaction(function () use ($companyId, $templateCode, $subjectType, $subjectIds, $actorId, &$documents) {
            foreach ($subjectIds as $subjectId) {
                $documents[] = $this->generateUseCase->execute(
                    $companyId,
                    $templateCode,
                    $subjectType,
                    $subjectId,
                    $actorId,
                );
            }
        });

        app(AuditLogger::class)->logCompany(
            companyId: $companyId,
            action: 'document.bulk_generated',
            targetType: 'generated_document',
            targetId: (string) ($documents[0]->id ?? 0),
            options: [
                'actorId' => $actorId,
                'metadata' => [
                    'category' => 'workforce.documents',
                    'template_code' => $templateCode,
                    'subject_type' => $subjectType,
                    'count' => count($documents),
                    'description' => "Bulk generated " . count($documents) . " documents from template '{$templateCode}'",
                ],
            ],
        );

        return $documents;
    }
}
