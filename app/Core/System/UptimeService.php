<?php

namespace App\Core\System;

class UptimeService
{
    /**
     * Return server uptime as a human-readable string.
     * Reads /proc/uptime on Linux, falls back to null.
     */
    public static function formatted(): ?string
    {
        try {
            $output = @shell_exec('uptime -s 2>/dev/null');

            if (!$output) {
                return null;
            }

            $bootTime = new \DateTimeImmutable(trim($output));
            $seconds = time() - $bootTime->getTimestamp();
        } catch (\Throwable) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];

        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        $parts[] = "{$minutes}m";

        return implode(' ', $parts);
    }
}
