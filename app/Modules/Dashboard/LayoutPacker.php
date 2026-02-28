<?php

namespace App\Modules\Dashboard;

/**
 * First-fit row-by-row packing algorithm for dashboard grid.
 *
 * Places widgets in a 12-column grid, scanning left-to-right, top-to-bottom.
 * Returns packed tiles and any widgets that couldn't be placed (pending suggestions).
 */
final class LayoutPacker
{
    public const GRID_COLS = 12;

    private const MAX_Y = 200; // Safety bound for row scanning

    /**
     * Pack new widgets into an existing layout.
     *
     * @param  array<array{key: string, x: int, y: int, w: int, h: int, scope: string, config?: array}>  $existing  Already placed tiles
     * @param  array<array{key: string, scope: string, config?: array}>  $newWidgets  Widgets to place (uses manifest defaults)
     * @return array{packed: array[], pending: string[]}
     */
    public static function pack(array $existing, array $newWidgets): array
    {
        // Sparse occupancy grid: $grid[$y][$x] = true if cell is occupied
        $grid = [];
        $packed = $existing;
        $pending = [];

        // Mark existing tiles in the grid
        foreach ($existing as $tile) {
            self::markOccupied($grid, $tile['x'], $tile['y'], $tile['w'], $tile['h']);
        }

        if (count($packed) >= LayoutValidator::MAX_TILES) {
            return ['packed' => $packed, 'pending' => array_column($newWidgets, 'key')];
        }

        foreach ($newWidgets as $widget) {
            if (count($packed) >= LayoutValidator::MAX_TILES) {
                $pending[] = $widget['key'];

                continue;
            }

            $manifest = DashboardWidgetRegistry::find($widget['key']);
            $layout = $manifest?->layout() ?? ['default_w' => 4, 'default_h' => 4];
            $w = $layout['default_w'];
            $h = $layout['default_h'];

            $position = self::findFirstFit($grid, $w, $h);

            if ($position) {
                self::markOccupied($grid, $position['x'], $position['y'], $w, $h);

                $packed[] = array_merge($widget, [
                    'x' => $position['x'],
                    'y' => $position['y'],
                    'w' => $w,
                    'h' => $h,
                ]);
            } else {
                $pending[] = $widget['key'];
            }
        }

        return ['packed' => $packed, 'pending' => $pending];
    }

    /**
     * Find the first available position for a rectangle of size w x h.
     *
     * @return array{x: int, y: int}|null
     */
    private static function findFirstFit(array $grid, int $w, int $h): ?array
    {
        for ($y = 0; $y < self::MAX_Y; $y++) {
            for ($x = 0; $x <= self::GRID_COLS - $w; $x++) {
                if (self::canPlace($grid, $x, $y, $w, $h)) {
                    return ['x' => $x, 'y' => $y];
                }
            }
        }

        return null;
    }

    private static function canPlace(array $grid, int $x, int $y, int $w, int $h): bool
    {
        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $w; $dx++) {
                if (!empty($grid[$y + $dy][$x + $dx])) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function markOccupied(array &$grid, int $x, int $y, int $w, int $h): void
    {
        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $w; $dx++) {
                $grid[$y + $dy][$x + $dx] = true;
            }
        }
    }
}
