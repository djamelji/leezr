<?php

namespace App\Modules\Dashboard;

/**
 * Validates dashboard layout tiles against grid constraints and widget manifests.
 */
final class LayoutValidator
{
    public const MAX_TILES = 30;

    public const GRID_COLS = 12;

    /**
     * @param  array<array{key: string, x: int, y: int, w: int, h: int, scope: string, config?: array}>  $tiles
     * @return array{valid: bool, errors: string[]}
     */
    public static function validate(array $tiles): array
    {
        $errors = [];

        if (count($tiles) > self::MAX_TILES) {
            $errors[] = 'Maximum ' . self::MAX_TILES . ' tiles allowed.';
        }

        foreach ($tiles as $i => $tile) {
            // Grid bounds
            if ($tile['x'] < 0) {
                $errors[] = "Tile {$i}: x must be >= 0.";
            }
            if ($tile['y'] < 0) {
                $errors[] = "Tile {$i}: y must be >= 0.";
            }
            if (($tile['x'] + $tile['w']) > self::GRID_COLS) {
                $errors[] = "Tile {$i}: x+w ({$tile['x']}+{$tile['w']}) exceeds " . self::GRID_COLS . ' columns.';
            }

            // Manifest constraints
            $widget = DashboardWidgetRegistry::find($tile['key']);

            if ($widget) {
                $layout = $widget->layout();

                if ($tile['w'] < $layout['min_w'] || $tile['w'] > $layout['max_w']) {
                    $errors[] = "Tile {$i}: w={$tile['w']} outside [{$layout['min_w']},{$layout['max_w']}].";
                }
                if ($tile['h'] < $layout['min_h'] || $tile['h'] > $layout['max_h']) {
                    $errors[] = "Tile {$i}: h={$tile['h']} outside [{$layout['min_h']},{$layout['max_h']}].";
                }
            }
        }

        // Overlap detection (O(n^2), fine for max 30)
        $count = count($tiles);
        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                if (self::rectsOverlap($tiles[$a], $tiles[$b])) {
                    $errors[] = "Tiles {$a} and {$b} overlap.";
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private static function rectsOverlap(array $a, array $b): bool
    {
        return $a['x'] < ($b['x'] + $b['w'])
            && ($a['x'] + $a['w']) > $b['x']
            && $a['y'] < ($b['y'] + $b['h'])
            && ($a['y'] + $a['h']) > $b['y'];
    }
}
