<?php

namespace App\Console\Commands;

use App\Core\Documents\CompanyDocumentSetting;
use App\Core\Documents\DocumentLifecycleService;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\DocumentTypeActivation;
use App\Core\Documents\MemberDocument;
use App\Core\Models\Company;
use App\Core\Models\Membership;
use App\Core\Models\User;
use App\Core\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-389/397: Daily check for expiring/expired member documents.
 *
 * Phase 1: Notify expiring_soon / expired documents
 * Phase 2: Auto-renew — create DocumentRequest if company setting enabled
 * Phase 3: Auto-remind — re-notify unanswered requests after X days
 */
class DocumentCheckExpirationCommand extends Command
{
    protected $signature = 'documents:check-expiration {--dry-run : Show what would be notified without sending}';

    protected $description = 'Check for expiring and expired member documents, auto-renew and auto-remind';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Phase 1: Expiration notifications
        $this->info('Phase 1: Expiration notifications...');
        $phase1 = $this->phaseExpirationNotifications($dryRun);

        // Phase 2: Auto-renewal
        $this->info('Phase 2: Auto-renewal...');
        $phase2 = $this->phaseAutoRenew($dryRun);

        // Phase 3: Auto-remind
        $this->info('Phase 3: Auto-remind...');
        $phase3 = $this->phaseAutoRemind($dryRun);

        $this->info("Summary: Phase1({$phase1['notified']} notified), Phase2({$phase2['created']} renewed), Phase3({$phase3['reminded']} reminded)");

        Log::info('[documents:check-expiration] finished', [
            'phase1_notified' => $phase1['notified'],
            'phase2_created' => $phase2['created'],
            'phase3_reminded' => $phase3['reminded'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Phase 1: Notify about expiring_soon / expired documents.
     */
    private function phaseExpirationNotifications(bool $dryRun): array
    {
        $notified = 0;
        $skipped = 0;

        $documents = MemberDocument::whereNotNull('expires_at')
            ->with(['documentType', 'user', 'company'])
            ->get();

        foreach ($documents as $document) {
            $company = $document->company;
            $user = $document->user;
            $type = $document->documentType;

            if (! $company || ! $user || ! $type) {
                $skipped++;

                continue;
            }

            $activation = DocumentTypeActivation::where('company_id', $company->id)
                ->where('document_type_id', $type->id)
                ->where('enabled', true)
                ->first();

            if (! $activation) {
                $skipped++;

                continue;
            }

            $status = DocumentLifecycleService::computeFromDate(
                hasUpload: true,
                expiresAt: $document->expires_at,
            );

            if ($status === DocumentLifecycleService::STATUS_EXPIRING_SOON) {
                $topicKey = 'documents.expiring_soon';
            } elseif ($status === DocumentLifecycleService::STATUS_EXPIRED) {
                $topicKey = 'documents.expired';
            } else {
                continue;
            }

            $entityKey = "member_document:{$user->id}:{$type->code}";

            if ($dryRun) {
                $this->line("  [DRY-RUN] {$topicKey}: {$user->name} — {$type->label} (expires {$document->expires_at->toDateString()})");
                $notified++;

                continue;
            }

            try {
                NotificationDispatcher::send(
                    topicKey: $topicKey,
                    recipients: [$user],
                    payload: [
                        'document_type' => $type->label,
                        'document_code' => $type->code,
                        'expires_at' => $document->expires_at->toDateString(),
                        'member_name' => $user->name,
                        'link' => '/account-settings/documents',
                    ],
                    company: $company,
                    entityKey: $entityKey,
                );

                $adminUserIds = Membership::where('company_id', $company->id)
                    ->where('user_id', '!=', $user->id)
                    ->where(function ($q) {
                        $q->where('role', 'owner')
                            ->orWhereHas('companyRole', fn ($r) => $r->where('is_administrative', true));
                    })
                    ->pluck('user_id');

                if ($adminUserIds->isNotEmpty()) {
                    $admins = User::whereIn('id', $adminUserIds)->get();
                    NotificationDispatcher::send(
                        topicKey: $topicKey,
                        recipients: $admins,
                        payload: [
                            'document_type' => $type->label,
                            'document_code' => $type->code,
                            'expires_at' => $document->expires_at->toDateString(),
                            'member_name' => $user->name,
                            'link' => '/company/documents/compliance',
                        ],
                        company: $company,
                        entityKey: $entityKey,
                    );
                }

                $notified++;
            } catch (\Throwable $e) {
                Log::warning('[documents:check-expiration] Phase 1 failed', [
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'document_type' => $type->code,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  Failed: {$user->name} — {$type->label} — {$e->getMessage()}");
            }
        }

        return ['notified' => $notified, 'skipped' => $skipped];
    }

    /**
     * Phase 2: Auto-create DocumentRequest for documents expiring within renew_days_before.
     * Only for companies with auto_renew_enabled.
     */
    private function phaseAutoRenew(bool $dryRun): array
    {
        $created = 0;

        $settings = CompanyDocumentSetting::where('auto_renew_enabled', true)->get();

        foreach ($settings as $setting) {
            $thresholdDate = now()->addDays($setting->renew_days_before);

            // Find documents expiring within threshold that don't already have a pending request
            $documents = MemberDocument::where('company_id', $setting->company_id)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $thresholdDate)
                ->where('expires_at', '>', now())
                ->with(['documentType', 'user'])
                ->get();

            foreach ($documents as $document) {
                if (! $document->user || ! $document->documentType) {
                    continue;
                }

                // Skip if there's already a pending request
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
                            'link' => '/account-settings/documents',
                        ],
                        company: Company::find($setting->company_id),
                        entityKey: "document_request:{$document->user_id}:{$document->documentType->code}",
                    );

                    $created++;
                } catch (\Throwable $e) {
                    Log::warning('[documents:check-expiration] Phase 2 auto-renew failed', [
                        'company_id' => $setting->company_id,
                        'user_id' => $document->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['created' => $created];
    }

    /**
     * Phase 3: Auto-remind unanswered requests after remind_after_days.
     * Only for companies with auto_remind_enabled.
     */
    private function phaseAutoRemind(bool $dryRun): array
    {
        $reminded = 0;

        $settings = CompanyDocumentSetting::where('auto_remind_enabled', true)->get();

        foreach ($settings as $setting) {
            $thresholdDate = now()->subDays($setting->remind_after_days);

            $requests = DocumentRequest::where('company_id', $setting->company_id)
                ->where('status', DocumentRequest::STATUS_REQUESTED)
                ->where('requested_at', '<=', $thresholdDate)
                ->with(['user', 'documentType'])
                ->get();

            foreach ($requests as $request) {
                if (! $request->user || ! $request->documentType) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  [DRY-RUN] Auto-remind: {$request->user->name} — {$request->documentType->label} (requested {$request->requested_at->toDateString()})");
                    $reminded++;

                    continue;
                }

                try {
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
                    Log::warning('[documents:check-expiration] Phase 3 auto-remind failed', [
                        'company_id' => $setting->company_id,
                        'request_id' => $request->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return ['reminded' => $reminded];
    }
}
