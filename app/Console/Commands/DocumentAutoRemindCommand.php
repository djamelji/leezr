<?php

namespace App\Console\Commands;

use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentRequest;
use App\Core\Models\Company;
use App\Core\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-397: Send reminders for pending document requests.
 *
 * For each company with auto_remind_enabled, finds document requests
 * still in "requested" status past the remind_after_days threshold
 * and re-notifies the member. The requested_at is bumped to now()
 * so the next reminder waits another full cycle.
 */
class DocumentAutoRemindCommand extends Command implements Isolatable
{
    protected $signature = 'documents:auto-remind {--dry-run : Show what would be reminded without sending}';

    protected $description = 'Send reminders for pending document requests';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $reminded = 0;
        $failed = 0;

        $settings = CompanyDocumentSetting::where('auto_remind_enabled', true)->get();

        if ($settings->isEmpty()) {
            $this->info('No companies with auto-remind enabled.');
            Log::info('[documents:auto-remind] No companies with auto-remind enabled.');

            return self::SUCCESS;
        }

        $this->info("Processing {$settings->count()} company setting(s) with auto-remind enabled...");

        foreach ($settings as $setting) {
            $thresholdDate = now()->subDays($setting->remind_after_days);

            // Find requests still in "requested" status where requested_at is older than threshold
            $requests = DocumentRequest::where('company_id', $setting->company_id)
                ->where('status', DocumentRequest::STATUS_REQUESTED)
                ->where('requested_at', '<=', $thresholdDate)
                ->with(['user', 'documentType'])
                ->chunkById(100, function ($chunk) use ($setting, $dryRun, &$reminded, &$failed) {
                    foreach ($chunk as $request) {
                        if (! $request->user || ! $request->documentType) {
                            continue;
                        }

                        if ($dryRun) {
                            $this->line("  [DRY-RUN] Remind: {$request->user->name} — {$request->documentType->label} (requested {$request->requested_at->toDateString()})");
                            $reminded++;

                            continue;
                        }

                        try {
                            // Bump requested_at so the next reminder waits another full cycle
                            $request->update(['requested_at' => now()]);

                            NotificationDispatcher::send(
                                topicKey: 'documents.request_new',
                                recipients: [$request->user],
                                payload: [
                                    'document_type' => $request->documentType->label,
                                    'document_code' => $request->documentType->code,
                                    'link' => '/account-settings/documents',
                                ],
                                company: Company::find($setting->company_id),
                                entityKey: "document_request:{$request->user_id}:{$request->documentType->code}",
                            );

                            $reminded++;
                        } catch (\Throwable $e) {
                            $failed++;
                            Log::warning('[documents:auto-remind] Failed to remind', [
                                'company_id' => $setting->company_id,
                                'request_id' => $request->id,
                                'user_id' => $request->user_id,
                                'error' => $e->getMessage(),
                            ]);
                            $this->error("  Failed: {$request->user->name} — {$request->documentType->label} — {$e->getMessage()}");
                        }
                    }
                });
        }

        $this->info("Done. Reminded: {$reminded}, Failed: {$failed}");

        Log::info('[documents:auto-remind] finished', [
            'reminded' => $reminded,
            'failed' => $failed,
        ]);

        return self::SUCCESS;
    }
}
