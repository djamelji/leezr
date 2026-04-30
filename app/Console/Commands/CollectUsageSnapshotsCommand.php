<?php

namespace App\Console\Commands;

use App\Core\Models\Company;
use App\Core\Usage\UsageSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CollectUsageSnapshotsCommand extends Command
{
    protected $signature = 'usage:collect-snapshots';

    protected $description = 'Collect daily usage snapshots for all companies';

    public function handle(): int
    {
        $today = now()->toDateString();
        $companies = Company::all();

        foreach ($companies as $company) {
            $aiRequests = 0;
            $aiTokens = 0;
            $emailsSent = 0;
            $activeMembers = 0;
            $storageBytes = 0;

            // AI requests from ai_request_logs
            // Columns: input_tokens, output_tokens (no total_tokens column)
            try {
                $aiData = DB::table('ai_request_logs')
                    ->where('company_id', $company->id)
                    ->whereDate('created_at', $today)
                    ->selectRaw('COUNT(*) as count, COALESCE(SUM(input_tokens + output_tokens), 0) as tokens')
                    ->first();
                $aiRequests = $aiData->count ?? 0;
                $aiTokens = (int) ($aiData->tokens ?? 0);
            } catch (\Throwable) {
            }

            // Emails sent (status enum: queued, sent, failed)
            try {
                $emailsSent = DB::table('email_logs')
                    ->where('company_id', $company->id)
                    ->whereDate('created_at', $today)
                    ->where('status', 'sent')
                    ->count();
            } catch (\Throwable) {
            }

            // Active members — memberships table (no status column, count all)
            try {
                $activeMembers = DB::table('memberships')
                    ->where('company_id', $company->id)
                    ->count();
            } catch (\Throwable) {
            }

            // Storage — sum of file sizes from company_documents + member_documents
            try {
                $companyDocs = DB::table('company_documents')
                    ->where('company_id', $company->id)
                    ->sum('file_size_bytes') ?? 0;
                $memberDocs = DB::table('member_documents')
                    ->where('company_id', $company->id)
                    ->sum('file_size_bytes') ?? 0;
                $storageBytes = (int) $companyDocs + (int) $memberDocs;
            } catch (\Throwable) {
                $storageBytes = 0;
            }

            UsageSnapshot::updateOrCreate(
                ['company_id' => $company->id, 'date' => $today],
                [
                    'api_requests' => 0, // Will be filled by middleware in future
                    'ai_requests' => $aiRequests,
                    'ai_tokens' => $aiTokens,
                    'emails_sent' => $emailsSent,
                    'active_members' => $activeMembers,
                    'storage_bytes' => $storageBytes,
                ],
            );
        }

        $this->info("Collected usage snapshots for {$companies->count()} companies.");

        return self::SUCCESS;
    }
}
