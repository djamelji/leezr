<?php

namespace App\Core\Home;

use App\Core\Modules\ModuleGate;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use App\Core\Navigation\NavGroupRegistry;

/**
 * ADR-776 — assembles the « À traiter » list of the company home from the ACTIVE modules.
 *
 * The Core knows no business : it collects what modules contribute, keeps what is real (count > 0),
 * removes duplicates, orders by urgency. A failing contribution is isolated — it is reported and
 * named in `unavailable`, the rest of the home is unaffected.
 */
class HomePrioritiesBuilder
{
    /** @return array{priorities: array<int, array<string, mixed>>, unavailable: array<int, string>} */
    public static function build(HomeContext $home): array
    {
        $items = [];
        $unavailable = [];

        foreach (ModuleRegistry::forScope('company') as $manifest) {
            /** @var ModuleManifest $manifest */
            if ($manifest->capabilities->priorityProviders === [] || ! ModuleGate::isActive($home->company, $manifest->key)) {
                continue;
            }
            foreach ($manifest->capabilities->priorityProviders as $providerClass) {
                try {
                    foreach (app($providerClass)->priorities($home) as $item) {
                        if (($item['count'] ?? 0) > 0 && ! isset($items[$item['key']]) && NavGroupRegistry::space($item['space'] ?? '') !== null) {
                            $items[$item['key']] = $item;
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $unavailable[] = $manifest->key;
                }
            }
        }

        $rank = array_flip(HomePriority::SEVERITIES);
        $items = array_values($items);
        usort($items, fn (array $a, array $b) => [$rank[$a['severity']] ?? 9, -$a['count']] <=> [$rank[$b['severity']] ?? 9, -$b['count']]);

        return ['priorities' => $items, 'unavailable' => array_values(array_unique($unavailable))];
    }
}
