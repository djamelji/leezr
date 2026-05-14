<?php

namespace App\Core\Workforce\Services;

use App\Core\Workforce\TimeEntry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

class AccountingDayAllocator
{
    /**
     * Allocate a TimeEntry's worked hours across accounting days.
     *
     * A single TimeEntry spanning midnight (e.g., 22:00 → 06:00) is never split
     * into multiple records. Instead, this service produces an allocation array
     * showing how many minutes belong to each calendar day.
     *
     * @return array<int, array{date: string, minutes: int}>
     */
    public function allocate(TimeEntry $entry): array
    {
        if (! $entry->clock_in || ! $entry->clock_out) {
            return [];
        }

        $clockIn = CarbonImmutable::parse($entry->clock_in);
        $clockOut = CarbonImmutable::parse($entry->clock_out);

        // Same calendar day — no split needed
        if ($clockIn->isSameDay($clockOut)) {
            return [
                [
                    'date' => $clockIn->toDateString(),
                    'minutes' => (int) $clockIn->diffInMinutes($clockOut),
                ],
            ];
        }

        // Spans midnight — allocate per day
        $allocations = [];
        $cursor = $clockIn;

        while ($cursor->lt($clockOut)) {
            $endOfDay = $cursor->copy()->endOfDay()->addSecond(); // start of next day
            $segmentEnd = $clockOut->lt($endOfDay) ? $clockOut : $endOfDay;
            $minutes = (int) $cursor->diffInMinutes($segmentEnd);

            if ($minutes > 0) {
                $allocations[] = [
                    'date' => $cursor->toDateString(),
                    'minutes' => $minutes,
                ];
            }

            $cursor = $segmentEnd;
        }

        return $allocations;
    }

    /**
     * Get total allocated minutes for a specific date from a set of time entries.
     *
     * @param  TimeEntry[]  $entries
     */
    public function totalForDate(array $entries, Carbon|CarbonImmutable|string $date): int
    {
        $dateStr = $date instanceof Carbon || $date instanceof CarbonImmutable
            ? $date->toDateString()
            : $date;

        $total = 0;

        foreach ($entries as $entry) {
            foreach ($this->allocate($entry) as $allocation) {
                if ($allocation['date'] === $dateStr) {
                    $total += $allocation['minutes'];
                }
            }
        }

        return $total;
    }
}
