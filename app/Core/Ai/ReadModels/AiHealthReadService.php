<?php

namespace App\Core\Ai\ReadModels;

use App\Core\Ai\AiGatewayManager;
use App\Core\Ai\PlatformAiModule;
use App\Core\Documents\MemberDocument;
use Illuminate\Support\Facades\DB;

/**
 * ADR-422: AI health read service — aggregates health status for monitoring.
 */
class AiHealthReadService
{
    public static function check(): array
    {
        $manager = app(AiGatewayManager::class);
        $adapter = $manager->driver();
        $healthResult = $adapter->healthCheck();

        $activeModule = PlatformAiModule::where('is_active', true)->first();

        // Queue health
        $pendingJobs = DB::table('jobs')->where('queue', 'ai')->count();
        $failedJobs = DB::table('failed_jobs')
            ->where('queue', 'ai')
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        // Document processing stats
        $stats = [
            'pending' => MemberDocument::where('ai_status', 'pending')->count(),
            'processing' => MemberDocument::where('ai_status', 'processing')->count(),
            'completed' => MemberDocument::where('ai_status', 'completed')->count(),
            'failed' => MemberDocument::where('ai_status', 'failed')->count(),
        ];

        $queueHealthy = $pendingJobs < 50 && $failedJobs === 0;
        $providerHealthy = $healthResult->isHealthy();
        $overallHealthy = $queueHealthy && $providerHealthy && $activeModule !== null;

        return [
            'healthy' => $overallHealthy,
            'provider' => [
                'key' => $adapter->key(),
                'healthy' => $providerHealthy,
                'status' => $healthResult->status,
                'message' => $healthResult->message,
            ],
            'module' => $activeModule ? [
                'key' => $activeModule->provider_key,
                'name' => $activeModule->name,
                'health_status' => $activeModule->health_status,
            ] : null,
            'queue' => [
                'healthy' => $queueHealthy,
                'pending_jobs' => $pendingJobs,
                'failed_jobs_24h' => $failedJobs,
            ],
            'documents' => $stats,
        ];
    }
}
