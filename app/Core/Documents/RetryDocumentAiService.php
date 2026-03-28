<?php

namespace App\Core\Documents;

use App\Jobs\Documents\ProcessDocumentAiJob;
use Illuminate\Validation\ValidationException;

/**
 * ADR-422: Retry AI analysis for a failed/pending document.
 */
class RetryDocumentAiService
{
    public static function retry(MemberDocument|CompanyDocument $document): void
    {
        if (! in_array($document->ai_status, ['failed', 'pending', null], true)) {
            throw ValidationException::withMessages([
                'ai_status' => [__('documents.retryAiOnlyFailed')],
            ]);
        }

        $document->update([
            'ai_status' => 'pending',
            'ai_analysis' => null,
            'ai_insights' => null,
        ]);

        ProcessDocumentAiJob::dispatch($document::class, $document->id, $document->document_type_id);
    }
}
