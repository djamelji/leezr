<?php

namespace App\Console\Commands;

use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-397: Re-request documents expiring soon.
 *
 * For each company with auto_renew_enabled, finds MemberDocuments
 * expiring within renew_days_before that have an approved (or no pending)
 * DocumentRequest. Creates a new "requested" DocumentRequest and
 * notifies the member to upload a fresh document.
 */
class DocumentAutoRenewCommand extends Command implements Isolatable
{
    protected $signature = 'documents:auto-renew {--dry-run : Show what would be re-requested without acting}';

    protected $description = 'Re-request documents expiring soon';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $created = 0;
        $failed = 0;

        $settings = CompanyDocumentSetting::where('auto_renew_enabled', true)->get();

        if ($settings->isEmpty()) {
            $this->info('No companies with auto-renew enabled.');
            Log::info('[documents:auto-renew] No companies with auto-renew enabled.');

            return self::SUCCESS;
        }

        $this->info("Processing {$settings->count()} company setting(s) with auto-renew enabled...");

        foreach ($settings as $setting) {
            $thresholdDate = now()->addDays($setting->renew_days_before);

            // Find documents expiring within threshold (but not already expired)
            MemberDocument::where('company_id', $setting->company_id)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $thresholdDate)
                ->where('expires_at', '>', now())
                ->with(['documentType', 'user'])
                ->chunkById(100, function ($documents) use ($setting, $dryRun, &$created, &$failed) {
                    foreach ($documents as $document) {
                        if (! $document->user || ! $document->documentType) {
                            continue;
                        }

                        // Skip if there's already a pending request for this document type
                        $existingRequest = DocumentRequest::where('company_id', $setting->company_id)
                            ->where('user_id', $document->user_id)
                            ->where('document_type_id', $document->document_type_id)
                            ->where('status', DocumentRequest::STATUS_REQUESTED)
                            ->exists();

                        if ($existingRequest) {
                            continue;
                        }

                        if ($dryRun) {
                            $this->line("  [DRY-RUN] Auto-renew: {$document->user->name} — {$document->documentType->label} (expires {$document->expires_at->toDateString()})");
                            $created++;

                            continue;
                        }

                        try {
                            DocumentRequest::create([
                                'company_id' => $setting->company_id,
                                'user_id' => $document->user_id,
                                'document_type_id' => $document->document_type_id,
                                'status' => DocumentRequest::STATUS_REQUESTED,
                                'requested_at' => now(),
                            ]);

                            NotificationDispatcher::send(
                                topicKey: 'documents.request_new',
                                recipients: [$document->user],
                                payload: [
                                    'document_type' => $document->documentType->label,
                                    'document_code' => $document->documentType->code,
                                    'expires_at' => $document->expires_at->toDateString(),
                                    'link' => '/account-settings/documents',
                                ],
                                company: Company::find($setting->company_id),
                                entityKey: "document_request:{$document->user_id}:{$document->documentType->code}",
                            );

                            $created++;
                        } catch (\Throwable $e) {
                            $failed++;
                            Log::warning('[documents:auto-renew] Failed to create renewal request', [
                                'company_id' => $setting->company_id,
                                'user_id' => $document->user_id,
                                'document_type_id' => $document->document_type_id,
                                'error' => $e->getMessage(),
                            ]);
                            $this->error("  Failed: {$document->user->name} — {$document->documentType->label} — {$e->getMessage()}");
                        }
                    }
                });
        }

        $this->info("Done. Renewal requests created: {$created}, Failed: {$failed}");

        Log::info('[documents:auto-renew] finished', [
            'created' => $created,
            'failed' => $failed,
        ]);

        return self::SUCCESS;
    }
}
