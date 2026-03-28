<?php

namespace App\Console\Commands;

use App\Core\Ai\AiGatewayManager;
use App\Core\Ai\PlatformAiModule;
use App\Core\Documents\MemberDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADR-422: AI health check command for monitoring.
 */
class AiHealthCommand extends Command
{
    protected $signature = 'ai:health';

    protected $description = 'Check AI pipeline health: provider, queue, document processing stats';

    public function handle(): int
    {
        $this->info('AI Health Check');
        $this->line('');

        // 1. Provider health
        $manager = app(AiGatewayManager::class);
        $adapter = $manager->driver();
        $health = $adapter->healthCheck();

        $providerIcon = $health->isHealthy() ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
        $this->line("  Provider: {$adapter->key()} [{$providerIcon}] {$health->message}");

        // 2. Active module
        $activeModule = PlatformAiModule::where('is_active', true)->first();
        if ($activeModule) {
            $moduleIcon = $activeModule->health_status === 'healthy' ? '<fg=green>OK</>' : '<fg=yellow>WARN</>';
            $this->line("  Module: {$activeModule->name} ({$activeModule->provider_key}) [{$moduleIcon}]");
        } else {
            $this->line('  Module: <fg=red>NONE ACTIVE</>');
        }

        // 3. Queue stats
        $pendingJobs = DB::table('jobs')->where('queue', 'ai')->count();
        $failedJobs = DB::table('failed_jobs')
            ->where('queue', 'ai')
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        $queueIcon = ($pendingJobs < 50 && $failedJobs === 0) ? '<fg=green>OK</>' : '<fg=yellow>WARN</>';
        $this->line("  Queue: [{$queueIcon}] {$pendingJobs} pending, {$failedJobs} failed (24h)");

        // 4. Document processing stats
        $stats = [
            'pending' => MemberDocument::where('ai_status', 'pending')->count(),
            'processing' => MemberDocument::where('ai_status', 'processing')->count(),
            'completed' => MemberDocument::where('ai_status', 'completed')->count(),
            'failed' => MemberDocument::where('ai_status', 'failed')->count(),
        ];

        $this->line('');
        $this->info('Document AI Stats:');
        $this->line("  Pending:    {$stats['pending']}");
        $this->line("  Processing: {$stats['processing']}");
        $this->line("  Completed:  {$stats['completed']}");
        $this->line("  Failed:     {$stats['failed']}");

        $overall = $health->isHealthy() && $activeModule && $pendingJobs < 50 && $failedJobs === 0;
        $this->line('');
        $this->line($overall ? '<fg=green>Overall: HEALTHY</>' : '<fg=red>Overall: UNHEALTHY</>');

        return $overall ? self::SUCCESS : self::FAILURE;
    }
}
