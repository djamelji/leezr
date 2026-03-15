<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Modules\Dashboard\Contracts\WidgetManifest;
use App\Modules\Dashboard\PeriodParser;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-156: Dataset-driven batch resolver for billing dashboard widgets.
 *
 * Groups widgets by datasetKey, loads each dataset ONCE, caches per dataset+scope+period+companyId,
 * then fans out to widget->transform().
 */
class PlatformBillingDashboardReadService
{
    /** TTL in seconds by dataset prefix */
    private const TTLS = [
        'billing.activity' => 30,
        'billing.kpis' => 30,
        'billing.risk' => 30,
        'billing.timeseries' => 60,
    ];

    /**
     * Resolve multiple batchable widgets in a single pass.
     *
     * @param array<array{widget: WidgetManifest, request: array}> $requests
     * @return array<array{key: string, data: array}>
     */
    public function resolveMany(array $requests): array
    {
        // Group by datasetKey + context hash (scope, companyId, period)
        $groups = [];

        foreach ($requests as $entry) {
            $widget = $entry['widget'];
            $req = $entry['request'];
            $datasetKey = $widget->datasetKey();
            $contextHash = $this->contextHash($req);
            $groupKey = $datasetKey . ':' . $contextHash;

            $groups[$groupKey] ??= [
                'datasetKey' => $datasetKey,
                'request' => $req,
                'widgets' => [],
            ];

            $groups[$groupKey]['widgets'][] = $widget;
        }

        // Load each unique dataset ONCE (with cache)
        $results = [];

        foreach ($groups as $group) {
            $dataset = $this->loadDataset($group['datasetKey'], $group['request']);

            foreach ($group['widgets'] as $widget) {
                $results[] = $widget->transform($dataset, $group['request']);
            }
        }

        return $results;
    }

    private function loadDataset(string $datasetKey, array $context): array
    {
        $scope = $context['scope'] ?? 'global';
        $companyId = $context['company_id'] ?? null;
        $period = $context['period'] ?? '30d';

        $cacheKey = "dashboard:billing:{$datasetKey}:{$scope}:{$companyId}:{$period}";
        $ttl = self::TTLS[$datasetKey] ?? 30;

        return Cache::remember($cacheKey, $ttl, function () use ($datasetKey, $scope, $companyId, $period) {
            $from = PeriodParser::parse($period);
            $to = now();

            return match ($datasetKey) {
                'billing.activity' => PlatformBillingWidgetsReadService::activityDataset($scope, $companyId, $from, $to),
                'billing.kpis' => PlatformBillingWidgetsReadService::kpisDataset($scope, $companyId, $from, $to),
                'billing.risk' => PlatformBillingWidgetsReadService::riskDataset($scope, $companyId),
                'billing.timeseries' => PlatformBillingWidgetsReadService::timeseriesDataset($scope, $companyId, $from, $to),
                default => [],
            };
        });
    }

    private function contextHash(array $context): string
    {
        return implode('|', [
            $context['scope'] ?? 'global',
            $context['company_id'] ?? '0',
            $context['period'] ?? '30d',
        ]);
    }
}
