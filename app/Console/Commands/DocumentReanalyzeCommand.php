<?php

namespace App\Console\Commands;

use App\Core\Documents\CompanyDocument;
use App\Core\Documents\MemberDocument;
use App\Jobs\Documents\ProcessDocumentAiJob;
use Illuminate\Console\Command;

/**
 * ADR-419: Re-dispatch AI analysis for documents that were uploaded
 * while the AI pipeline was disabled (missing PlatformAiModule).
 *
 * Only targets documents that have a file but no ai_analysis.
 */
class DocumentReanalyzeCommand extends Command
{
    protected $signature = 'documents:reanalyze {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Re-dispatch AI analysis for documents missing ai_analysis';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // ADR-422: Target docs without analysis OR with failed status
        $memberDocs = MemberDocument::whereNotNull('file_path')
            ->where(fn ($q) => $q->whereNull('ai_analysis')->orWhere('ai_status', 'failed'))
            ->get();

        $companyDocs = CompanyDocument::whereNotNull('file_path')
            ->where(fn ($q) => $q->whereNull('ai_analysis')->orWhere('ai_status', 'failed'))
            ->get();

        $total = $memberDocs->count() + $companyDocs->count();

        if ($total === 0) {
            $this->info('No documents need reanalysis.');

            return self::SUCCESS;
        }

        $this->info("Found {$memberDocs->count()} member docs + {$companyDocs->count()} company docs without AI analysis.");

        if ($dryRun) {
            $this->warn('Dry run — no jobs dispatched.');

            return self::SUCCESS;
        }

        foreach ($memberDocs as $doc) {
            $doc->update(['ai_status' => 'pending', 'ai_analysis' => null, 'ai_insights' => null]);
            ProcessDocumentAiJob::dispatch(MemberDocument::class, $doc->id, $doc->document_type_id);
        }

        foreach ($companyDocs as $doc) {
            $doc->update(['ai_status' => 'pending', 'ai_analysis' => null, 'ai_insights' => null]);
            ProcessDocumentAiJob::dispatch(CompanyDocument::class, $doc->id, $doc->document_type_id);
        }

        $this->info("Dispatched {$total} AI analysis jobs to queue 'ai'.");

        return self::SUCCESS;
    }
}
