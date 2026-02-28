<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate platform_user_dashboard_layouts from col_span format to x/y/w/h grid format.
 *
 * Old format: {key, col_span, scope, config}
 * New format: {key, x, y, w, h, scope, config}
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('platform_user_dashboard_layouts')->get();

        foreach ($rows as $row) {
            $old = json_decode($row->layout_json, true) ?: [];

            // Check if already migrated (has 'x' key)
            if (!empty($old) && isset($old[0]['x'])) {
                continue;
            }

            $packed = [];
            $x = 0;
            $y = 0;
            $rowHeight = 0;

            foreach ($old as $item) {
                $w = $item['col_span'] ?? 4;
                $h = 4; // default height

                // Wrap to next row if overflows
                if ($x + $w > 12) {
                    $x = 0;
                    $y += $rowHeight;
                    $rowHeight = 0;
                }

                $packed[] = [
                    'key' => $item['key'],
                    'x' => $x,
                    'y' => $y,
                    'w' => $w,
                    'h' => $h,
                    'scope' => $item['scope'] ?? 'global',
                    'config' => $item['config'] ?? [],
                ];

                $x += $w;
                $rowHeight = max($rowHeight, $h);
            }

            DB::table('platform_user_dashboard_layouts')
                ->where('id', $row->id)
                ->update(['layout_json' => json_encode($packed)]);
        }
    }

    public function down(): void
    {
        // Reverse: convert x/y/w/h back to col_span
        $rows = DB::table('platform_user_dashboard_layouts')->get();

        foreach ($rows as $row) {
            $current = json_decode($row->layout_json, true) ?: [];

            if (!empty($current) && isset($current[0]['col_span'])) {
                continue;
            }

            $old = array_map(fn ($item) => [
                'key' => $item['key'],
                'col_span' => $item['w'] ?? 4,
                'scope' => $item['scope'] ?? 'global',
                'config' => $item['config'] ?? [],
            ], $current);

            DB::table('platform_user_dashboard_layouts')
                ->where('id', $row->id)
                ->update(['layout_json' => json_encode($old)]);
        }
    }
};
