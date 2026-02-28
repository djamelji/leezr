<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR-152 V5: Layout engine — ZERO COLLISION invariant.
 *
 * Mirrors the frontend resolveOverlaps + compactLayout pipeline.
 * Proves that after any mutation (drag, resize, add, remove),
 * no two tiles ever overlap.
 *
 * Pipeline: clamp → resolve → compact → assert.
 * Each widget keeps its own h (free height, no row unification).
 */
class LayoutEngineNoOverlapTest extends TestCase
{
    private int $cols = 12;

    private const WIDGET_MIN_W = 3;

    private const WIDGET_MIN_H = 2;

    private const WIDGET_MAX_H = 6;

    private const DASHBOARD_MAX_H = 24;

    // ── Engine (mirrors useDashboardGrid.js V5) ──

    private function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w']
            && $a['x'] + $a['w'] > $b['x']
            && $a['y'] < $b['y'] + $b['h']
            && $a['y'] + $a['h'] > $b['y'];
    }

    private function clampToBounds(array $tile): array
    {
        $w = max(1, min($tile['w'], $this->cols));

        // B3: Mobile (4 cols) → max w = 2. Desktop/tablet: min WIDGET_MIN_W
        if ($this->cols === 4) {
            $w = min(2, $w);
        } else {
            $w = max(self::WIDGET_MIN_W, $w);
        }

        $h = max(self::WIDGET_MIN_H, min(self::WIDGET_MAX_H, $tile['h']));
        $x = max(0, min($tile['x'], $this->cols - $w));
        $y = max(0, $tile['y']);

        return array_merge($tile, compact('x', 'y', 'w', 'h'));
    }

    private function resolveOverlaps(array $tiles, ?string $movedKey): array
    {
        $layout = array_map(fn ($t) => $t, $tiles);
        $iterations = 0;

        while ($iterations < 200) {
            $fixedIdx = -1;
            $moveIdx = -1;

            for ($i = 0; $i < count($layout) && $fixedIdx === -1; $i++) {
                for ($j = $i + 1; $j < count($layout); $j++) {
                    if (! $this->overlaps($layout[$i], $layout[$j])) {
                        continue;
                    }

                    if ($layout[$i]['key'] === $movedKey) {
                        $fixedIdx = $i;
                        $moveIdx = $j;
                    } elseif ($layout[$j]['key'] === $movedKey) {
                        $fixedIdx = $j;
                        $moveIdx = $i;
                    } elseif ($layout[$i]['y'] < $layout[$j]['y']
                        || ($layout[$i]['y'] === $layout[$j]['y'] && $layout[$i]['x'] < $layout[$j]['x'])) {
                        $fixedIdx = $i;
                        $moveIdx = $j;
                    } else {
                        $fixedIdx = $j;
                        $moveIdx = $i;
                    }

                    break;
                }
            }

            if ($fixedIdx === -1) {
                break;
            }
            $iterations++;

            $fixed = $layout[$fixedIdx];
            $T = $layout[$moveIdx];
            $placed = false;

            // 1) SHIFT RIGHT — h=1 probe: decision independent of actual h
            $rightX = $fixed['x'] + $fixed['w'];
            if (! $placed && $rightX + $T['w'] <= $this->cols) {
                $probe = array_merge($T, ['x' => $rightX, 'h' => 1]);
                $noConflict = true;
                foreach ($layout as $k => $t) {
                    if ($k !== $moveIdx && $this->overlaps($probe, $t)) {
                        $noConflict = false;
                        break;
                    }
                }
                if ($noConflict) {
                    $layout[$moveIdx] = array_merge($T, ['x' => $rightX]);
                    $placed = true;
                }
            }

            // 2) SHIFT LEFT — h=1 probe: decision independent of actual h
            if (! $placed) {
                $leftX = $fixed['x'] - $T['w'];
                if ($leftX >= 0) {
                    $probe = array_merge($T, ['x' => $leftX, 'h' => 1]);
                    $noConflict = true;
                    foreach ($layout as $k => $t) {
                        if ($k !== $moveIdx && $this->overlaps($probe, $t)) {
                            $noConflict = false;
                            break;
                        }
                    }
                    if ($noConflict) {
                        $layout[$moveIdx] = array_merge($T, ['x' => $leftX]);
                        $placed = true;
                    }
                }
            }

            // 3) PUSH DOWN
            if (! $placed) {
                $pushY = $fixed['y'] + $fixed['h'];
                $pushX = max(0, min($T['x'], $this->cols - $T['w']));
                $candidate = array_merge($T, ['x' => $pushX, 'y' => $pushY]);
                $noConflict = true;
                foreach ($layout as $k => $t) {
                    if ($k !== $moveIdx && $this->overlaps($candidate, $t)) {
                        $noConflict = false;
                        break;
                    }
                }
                if ($noConflict) {
                    $layout[$moveIdx] = $candidate;
                    $placed = true;
                }
            }

            // 4) FALLBACK
            if (! $placed) {
                $maxY = 0;
                foreach ($layout as $k => $t) {
                    if ($k !== $moveIdx) {
                        $maxY = max($maxY, $t['y'] + $t['h']);
                    }
                }
                $layout[$moveIdx] = array_merge($T, ['x' => 0, 'y' => $maxY]);
            }
        }

        return $layout;
    }

    private function compactLayout(array $tiles): array
    {
        $layout = array_map(fn ($t) => $t, $tiles);
        usort($layout, fn ($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);

        for ($i = 0; $i < count($layout); $i++) {
            while ($layout[$i]['y'] > 0) {
                $candidate = array_merge($layout[$i], ['y' => $layout[$i]['y'] - 1]);
                $blocked = false;
                for ($j = 0; $j < count($layout); $j++) {
                    if ($i === $j) {
                        continue;
                    }
                    if ($this->overlaps($candidate, $layout[$j])) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    break;
                }
                $layout[$i] = $candidate;
            }
        }

        return $layout;
    }

    private function packRowsLeft(array $tiles): array
    {
        $sorted = array_map(fn ($t) => $t, $tiles);
        usort($sorted, fn ($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);

        $rowYs = array_values(array_unique(array_column($sorted, 'y')));
        sort($rowYs);

        $packed = [];
        $overflow = [];

        foreach ($rowYs as $rowY) {
            $row = array_values(array_filter($sorted, fn ($t) => $t['y'] === $rowY));
            usort($row, fn ($a, $b) => $a['x'] <=> $b['x']);
            $cursor = 0;

            foreach ($row as $tile) {
                $x = $cursor;
                while ($x + $tile['w'] <= $this->cols) {
                    $candidate = array_merge($tile, ['x' => $x]);
                    $blocked = false;
                    foreach ($packed as $p) {
                        if ($this->overlaps($candidate, $p)) {
                            $blocked = true;
                            break;
                        }
                    }
                    if (! $blocked) {
                        break;
                    }
                    $x++;
                }

                if ($x + $tile['w'] <= $this->cols) {
                    $packed[] = array_merge($tile, ['x' => $x]);
                    $cursor = $x + $tile['w'];
                } else {
                    $overflow[] = $tile;
                }
            }
        }

        if (! empty($overflow)) {
            $maxY = 0;
            foreach ($packed as $t) {
                $maxY = max($maxY, $t['y'] + $t['h']);
            }
            $cursor = 0;
            $rowMaxH = 0;
            $currentY = $maxY;

            foreach ($overflow as $tile) {
                if ($cursor + $tile['w'] > $this->cols) {
                    $currentY += $rowMaxH ?: 1;
                    $cursor = 0;
                    $rowMaxH = 0;
                }
                $packed[] = array_merge($tile, ['x' => $cursor, 'y' => $currentY]);
                $cursor += $tile['w'];
                $rowMaxH = max($rowMaxH, $tile['h']);
            }
        }

        return $packed;
    }

    private function reflowUpward(array $tiles): array
    {
        $layout = array_map(fn ($t) => $t, $tiles);
        usort($layout, fn ($a, $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);

        for ($i = 0; $i < count($layout); $i++) {
            $tile = $layout[$i];
            $bestCandidate = null;

            for ($y = 0; $y < $tile['y']; $y++) {
                for ($x = 0; $x + $tile['w'] <= $this->cols; $x++) {
                    $candidate = array_merge($tile, ['x' => $x, 'y' => $y]);
                    $blocked = false;
                    foreach ($layout as $idx => $t) {
                        if ($idx !== $i && $this->overlaps($candidate, $t)) {
                            $blocked = true;
                            break;
                        }
                    }
                    if (! $blocked) {
                        $bestCandidate = $candidate;
                        break;
                    }
                }
                if ($bestCandidate !== null) {
                    break;
                }
            }

            if ($bestCandidate !== null) {
                $layout[$i] = $bestCandidate;
            }
        }

        return $layout;
    }

    private function assertNoOverlapInLayout(array $tiles): bool
    {
        for ($i = 0; $i < count($tiles); $i++) {
            for ($j = $i + 1; $j < count($tiles); $j++) {
                if ($this->overlaps($tiles[$i], $tiles[$j])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Full pipeline: clamp → resolve → compact → packLeft → compact → reflow → packLeft → compact → assert.
     */
    private function applyPipeline(array $layout, ?string $movedKey): ?array
    {
        $tiles = array_map(fn ($t) => $this->clampToBounds($t), $layout);
        $tiles = $this->resolveOverlaps($tiles, $movedKey);
        $tiles = $this->compactLayout($tiles);
        $tiles = $this->packRowsLeft($tiles);
        $tiles = $this->compactLayout($tiles);
        $tiles = $this->reflowUpward($tiles);
        $tiles = $this->packRowsLeft($tiles);
        $tiles = $this->compactLayout($tiles);

        if (! $this->assertNoOverlapInLayout($tiles)) {
            return null;
        }
        foreach ($tiles as $t) {
            if ($t['y'] + $t['h'] > self::DASHBOARD_MAX_H) {
                return null;
            }
        }

        return $tiles;
    }

    // ── Tests ──

    public function test_move_onto_other_tile_no_overlap(): void
    {
        // A at (0,0,4,4), B at (4,0,4,4). Move A to (4,0) → overlaps B
        $layout = [
            ['key' => 'A', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 4],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 4],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result, 'Pipeline must resolve overlaps');
        $this->assertTrue($this->assertNoOverlapInLayout($result), 'No overlap in result');
    }

    public function test_three_tiles_cascading_overlap(): void
    {
        // A, B, C all at same position
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4],
            ['key' => 'B', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4],
            ['key' => 'C', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));
    }

    public function test_full_row_overlap_pushes_down(): void
    {
        // 3 tiles of w=4 at row 0. Move 4th tile of w=4 onto row 0
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'D', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'D');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));
    }

    public function test_resize_wider_no_overlap(): void
    {
        // A at (0,0,4,4), B at (4,0,4,4). A resized to w=8 → overlaps B
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 4],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));
        // A should keep its position (moved tile priority)
        $a = collect($result)->firstWhere('key', 'A');
        $this->assertEquals(0, $a['x']);
        $this->assertEquals(0, $a['y']);
        $this->assertEquals(8, $a['w']);
    }

    public function test_many_tiles_stress_no_overlap(): void
    {
        // 12 tiles all at (0,0,3,2) — h=2 so worst-case stacking stays under DASHBOARD_MAX_H=24
        $layout = [];
        for ($i = 0; $i < 12; $i++) {
            $layout[] = ['key' => "tile_$i", 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2];
        }

        $result = $this->applyPipeline($layout, 'tile_0');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));
        $this->assertCount(12, $result);
    }

    public function test_edge_tiles_at_grid_boundary(): void
    {
        // Tile at right edge resized wider → clamped + no overlap
        $layout = [
            ['key' => 'A', 'x' => 8, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'B', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));
        // A should be clamped: x + w <= 12
        $a = collect($result)->firstWhere('key', 'A');
        $this->assertLessThanOrEqual(12, $a['x'] + $a['w']);
    }

    public function test_shift_right_preferred_over_push_down(): void
    {
        // A at (0,0,4,3), B at (0,0,4,3). B should shift right to (4,0)
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));

        $a = collect($result)->firstWhere('key', 'A');
        $b = collect($result)->firstWhere('key', 'B');
        // A stays at (0,0), B shifts right to (4,0) — same row
        $this->assertEquals(0, $a['x']);
        $this->assertEquals(0, $a['y']);
        $this->assertEquals(4, $b['x']);
        $this->assertEquals(0, $b['y']);
    }

    // ── V5: Mobile clamp tests ──

    public function test_mobile_4col_clamps_width_to_2(): void
    {
        $this->cols = 4;

        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 0, 'y' => 3, 'w' => 3, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        foreach ($result as $tile) {
            $this->assertLessThanOrEqual(2, $tile['w'], "Tile {$tile['key']} w={$tile['w']} exceeds mobile max of 2");
            $this->assertLessThanOrEqual(4, $tile['x'] + $tile['w']);
        }

        $this->cols = 12;
    }

    public function test_mobile_two_tiles_per_row(): void
    {
        $this->cols = 4;

        // 3 tiles of w=2 should produce 2 per row + 1 on next row
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 2],
            ['key' => 'B', 'x' => 2, 'y' => 0, 'w' => 2, 'h' => 2],
            ['key' => 'C', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 2],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));

        // Invariant: max 2 tiles per row at 4 cols
        $rowCounts = [];
        foreach ($result as $tile) {
            $y = $tile['y'];
            $rowCounts[$y] = ($rowCounts[$y] ?? 0) + 1;
        }
        foreach ($rowCounts as $y => $count) {
            $this->assertLessThanOrEqual(2, $count, "Row y=$y has $count tiles (max 2 at 4 cols)");
        }

        $this->cols = 12;
    }

    // ── V5: Free height — resize only affects targeted tile ──

    public function test_resize_height_does_not_affect_neighbors(): void
    {
        // A(0,0,6,2) B(6,0,6,4) — different heights on same row is allowed
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 2],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 4],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $a = collect($result)->firstWhere('key', 'A');
        $b = collect($result)->firstWhere('key', 'B');
        // A keeps h=2, B keeps h=4 — no unification
        $this->assertEquals(2, $a['h']);
        $this->assertEquals(4, $b['h']);
    }

    public function test_height_change_does_not_alter_horizontal_packing(): void
    {
        // Same layout with h=2 vs h=4 for tile A → identical x positions
        $layoutH2 = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2],
            ['key' => 'B', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 4, 'y' => 2, 'w' => 4, 'h' => 3],
        ];
        $layoutH4 = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4],
            ['key' => 'B', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 4, 'y' => 2, 'w' => 4, 'h' => 3],
        ];

        $resultH2 = $this->applyPipeline($layoutH2, 'A');
        $resultH4 = $this->applyPipeline($layoutH4, 'A');

        $this->assertNotNull($resultH2);
        $this->assertNotNull($resultH4);

        // Extract x positions keyed by tile key
        $xH2 = collect($resultH2)->pluck('x', 'key')->toArray();
        $xH4 = collect($resultH4)->pluck('x', 'key')->toArray();
        $wH2 = collect($resultH2)->pluck('w', 'key')->toArray();
        $wH4 = collect($resultH4)->pluck('w', 'key')->toArray();

        // Horizontal positions must be identical regardless of h
        foreach (['A', 'B', 'C'] as $key) {
            $this->assertEquals($xH2[$key], $xH4[$key], "Tile $key: x differs between h=2 and h=4");
            $this->assertEquals($wH2[$key], $wH4[$key], "Tile $key: w differs between h=2 and h=4");
        }
    }

    public function test_two_small_beside_one_large(): void
    {
        // Large(0,0,6,6) SmallA(6,0,6,3) SmallB(6,3,6,3) — valid stacked layout
        $layout = [
            ['key' => 'Large', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 6],
            ['key' => 'SmallA', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'SmallB', 'x' => 6, 'y' => 3, 'w' => 6, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));

        $large = collect($result)->firstWhere('key', 'Large');
        $smallA = collect($result)->firstWhere('key', 'SmallA');
        $smallB = collect($result)->firstWhere('key', 'SmallB');

        // Heights preserved independently
        $this->assertEquals(6, $large['h']);
        $this->assertEquals(3, $smallA['h']);
        $this->assertEquals(3, $smallB['h']);
        // SmallA + SmallB stacked beside Large
        $this->assertEquals(0, $smallA['y']);
        $this->assertEquals(3, $smallB['y']);
    }

    // ── V5: Vertical resize limits ──

    public function test_widget_height_clamped_to_max_6(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 10],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $a = collect($result)->firstWhere('key', 'A');
        $this->assertEquals(6, $a['h'], 'h must be clamped to WIDGET_MAX_H=6');
    }

    public function test_widget_height_clamped_to_min_2(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 1],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $a = collect($result)->firstWhere('key', 'A');
        $this->assertEquals(2, $a['h'], 'h must be clamped to WIDGET_MIN_H=2');
    }

    public function test_dashboard_max_height_24_enforced(): void
    {
        // 5 tiles of h=6 stacked → total 30 rows, last tile at y=24 → exceeds DASHBOARD_MAX_H
        $layout = [];
        for ($i = 0; $i < 5; $i++) {
            $layout[] = ['key' => "tile_$i", 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6];
        }

        $result = $this->applyPipeline($layout, null);

        // Pipeline returns null: last tile would land at y=24, y+h=30 > 24
        $this->assertNull($result, 'Pipeline must reject layouts exceeding DASHBOARD_MAX_H=24');
    }

    public function test_resize_height_no_horizontal_effect(): void
    {
        // A(0,0,4,2) B(4,0,4,2). Resize A to h=6 → B must stay at x=4
        $layoutBefore = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 2],
        ];
        $layoutAfter = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 6],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 2],
        ];

        $before = $this->applyPipeline($layoutBefore, 'A');
        $after = $this->applyPipeline($layoutAfter, 'A');

        $this->assertNotNull($before);
        $this->assertNotNull($after);

        $bBefore = collect($before)->firstWhere('key', 'B');
        $bAfter = collect($after)->firstWhere('key', 'B');

        $this->assertEquals($bBefore['x'], $bAfter['x'], 'B x must not change when A height changes');
    }

    // ── V5: min_w = 3 ──

    public function test_widget_min_width_3_on_desktop(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $a = collect($result)->firstWhere('key', 'A');
        $this->assertEquals(3, $a['w'], 'w must be clamped to WIDGET_MIN_W=3');
    }

    public function test_four_w3_widgets_fit_12_cols(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 3],
            ['key' => 'B', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 3],
            ['key' => 'C', 'x' => 6, 'y' => 0, 'w' => 3, 'h' => 3],
            ['key' => 'D', 'x' => 9, 'y' => 0, 'w' => 3, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));
        // All 4 on same row, packed left
        foreach ($result as $tile) {
            $this->assertEquals(0, $tile['y']);
        }
    }

    // ── V5: Strict left row packing ──

    public function test_no_horizontal_gap_after_removal(): void
    {
        // A(0,0,4,3), C(8,0,4,3) — gap at x=4..7
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $a = collect($result)->firstWhere('key', 'A');
        $c = collect($result)->firstWhere('key', 'C');

        $this->assertEquals(0, $a['x']);
        $this->assertEquals(4, $c['x'], 'C must pack left to fill the gap');
    }

    public function test_overflow_goes_to_bottom_left(): void
    {
        // A(0,0,10,3) + B(0,0,3,3) — B can't fit on row with A(w=10)
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 3],
            ['key' => 'B', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result);
        $a = collect($result)->firstWhere('key', 'A');
        $b = collect($result)->firstWhere('key', 'B');

        $this->assertEquals(0, $a['x']);
        $this->assertEquals(0, $a['y']);
        $this->assertEquals(0, $b['x'], 'Overflow tile must go to x=0');
        $this->assertGreaterThan(0, $b['y'], 'Overflow tile must go below');
    }

    public function test_resize_wider_packs_neighbors_and_overflows(): void
    {
        // w1(0,0,6,3) w2(6,0,3,3) w3(9,0,3,3). Resize w1 to w=8
        $layout = [
            ['key' => 'W1', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 3],
            ['key' => 'W2', 'x' => 6, 'y' => 0, 'w' => 3, 'h' => 3],
            ['key' => 'W3', 'x' => 9, 'y' => 0, 'w' => 3, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'W1');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));

        $w1 = collect($result)->firstWhere('key', 'W1');
        $this->assertEquals(0, $w1['x']);
        $this->assertEquals(8, $w1['w']);

        // All tiles packed left on their rows — no gaps
        $rows = collect($result)->groupBy('y');
        foreach ($rows as $y => $tiles) {
            $sorted = $tiles->sortBy('x')->values();
            $cursor = 0;
            foreach ($sorted as $tile) {
                $this->assertEquals($cursor, $tile['x'], "Row y=$y: tile {$tile['key']} should be at x=$cursor (packed left)");
                $cursor += $tile['w'];
            }
        }
    }
}
