<?php

namespace App\Modules\Platform\AI\Http;

use App\Core\Ai\AiGatewayManager;
use App\Core\Ai\AiRequestLog;
use App\Core\Ai\ReadModels\AiHealthReadService;
use App\Core\Ai\ReadModels\PlatformAiGovernanceReadService;
use App\Core\Models\Company;
use App\Platform\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only endpoints for Platform AI admin.
 * Permission: view_ai
 */
class PlatformAiController
{
    public function providers(): JsonResponse
    {
        $modules = PlatformAiGovernanceReadService::listModules();

        return response()->json(['providers' => $modules]);
    }

    public function usage(Request $request): JsonResponse
    {
        $period = $request->input('period', '7d');
        $since = match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };

        $query = AiRequestLog::where('created_at', '>=', $since);

        $stats = [
            'total_requests' => (clone $query)->count(),
            'successful' => (clone $query)->where('status', 'success')->count(),
            'errors' => (clone $query)->where('status', 'error')->count(),
            'avg_latency_ms' => (int) (clone $query)->where('status', 'success')->avg('latency_ms'),
            'total_input_tokens' => (int) (clone $query)->sum('input_tokens'),
            'total_output_tokens' => (int) (clone $query)->sum('output_tokens'),
        ];

        $byProvider = (clone $query)
            ->selectRaw('provider, count(*) as total, avg(latency_ms) as avg_latency, sum(case when status = \'error\' then 1 else 0 end) as errors')
            ->groupBy('provider')
            ->get()
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'total' => $row->total,
                'avg_latency_ms' => (int) $row->avg_latency,
                'errors' => (int) $row->errors,
            ]);

        $byModule = (clone $query)
            ->whereNotNull('module_key')
            ->selectRaw('module_key, count(*) as total')
            ->groupBy('module_key')
            ->pluck('total', 'module_key');

        $recentRequests = (clone $query)->latest()
            ->limit(50)
            ->get()
            ->map(fn (AiRequestLog $log) => [
                'id' => $log->id,
                'provider' => $log->provider,
                'model' => $log->model,
                'capability' => $log->capability,
                'latency_ms' => $log->latency_ms,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'module_key' => $log->module_key,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        // Top consumers by company (ADR-462)
        $byCompany = (clone $query)
            ->whereNotNull('company_id')
            ->selectRaw('company_id, count(*) as total_requests, sum(input_tokens + output_tokens) as total_tokens, sum(case when status = \'error\' then 1 else 0 end) as errors')
            ->groupBy('company_id')
            ->orderByDesc('total_requests')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $company = Company::find($row->company_id);

                return [
                    'company_id' => $row->company_id,
                    'company_name' => $company?->name ?? "Company #{$row->company_id}",
                    'total_requests' => (int) $row->total_requests,
                    'total_tokens' => (int) $row->total_tokens,
                    'errors' => (int) $row->errors,
                ];
            });

        return response()->json([
            'stats' => $stats,
            'by_provider' => $byProvider,
            'by_module' => $byModule,
            'by_company' => $byCompany,
            'recent_requests' => $recentRequests,
            'period' => $period,
        ]);
    }

    public function routing(): JsonResponse
    {
        $settings = PlatformSetting::instance();
        $routing = $settings->ai['routing'] ?? [];

        // Also list available providers for UI select
        $availableProviders = AiGatewayManager::availableProviders();

        return response()->json([
            'routing' => $routing,
            'available_providers' => $availableProviders,
        ]);
    }

    public function config(): JsonResponse
    {
        $settings = PlatformSetting::instance();

        return response()->json([
            'config' => [
                'driver' => $settings->ai['driver'] ?? config('ai.driver'),
                'timeout' => $settings->ai['timeout'] ?? config('ai.ollama.timeout', 60),
            ],
            'defaults' => [
                'driver' => config('ai.driver'),
                'timeout' => config('ai.ollama.timeout', 60),
            ],
        ]);
    }

    /**
     * ADR-422: AI health check — queue, provider, pending jobs.
     */
    public function health(): JsonResponse
    {
        return response()->json(AiHealthReadService::check());
    }
}
