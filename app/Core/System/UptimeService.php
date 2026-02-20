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
        if (!is_readable('/proc/uptime')) {
            return null;
        }

        $raw = file_get_contents('/proc/uptime');

        if ($raw === false) {
            return null;
        }

        $seconds = (int) explode(' ', trim($raw))[0];

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
