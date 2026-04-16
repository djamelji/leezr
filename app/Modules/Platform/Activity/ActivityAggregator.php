<?php

namespace App\Modules\Platform\Activity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ADR-440: Aggregates consecutive events of same action within same minute.
 */
final class ActivityAggregator
{
    /**
     * Aggregate consecutive events of same action within the same minute.
     * Groups are collapsed into a single entry with count.
     */
    public static function aggregate(Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $result = collect();
        $buffer = [];
        $lastKey = null;

        foreach ($items as $item) {
            $minute = Carbon::parse($item['created_at'])->format('Y-m-d H:i');
            $key = $item['action'] . '|' . $minute;

            if ($key === $lastKey) {
                $buffer[] = $item;
            } else {
                if (!empty($buffer)) {
                    $result->push(self::flushGroup($buffer));
                }
                $buffer = [$item];
                $lastKey = $key;
            }
        }

        if (!empty($buffer)) {
            $result->push(self::flushGroup($buffer));
        }

        return $result;
    }

    private static function flushGroup(array $buffer): array
    {
        if (count($buffer) === 1) {
            return $buffer[0] + ['aggregated_count' => 1, 'aggregated_items' => []];
        }

        $first = $buffer[0];
        $count = count($buffer);

        return [
            ...$first,
            'description' => $count . 'x ' . ActivityDescriber::humanLabel($first['action']),
            'aggregated_count' => $count,
            'aggregated_items' => array_map(fn ($item) => [
                'id' => $item['id'],
                'description' => $item['description'],
                'target_type' => $item['target_type'],
                'target_id' => $item['target_id'],
                'company_name' => $item['company_name'],
                'company_id' => $item['company_id'],
            ], $buffer),
        ];
    }
}
