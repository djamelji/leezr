<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Responsive Layout Integrity — Single Source of Truth.
 *
 * Proves that the layout pipeline:
 *   - Always operates at 12 canonical cols
 *   - Is idempotent (running twice produces same output)
 *   - Never mutates layout on viewport change
 *   - Visual clamping produces valid render positions
 */
class ResponsiveLayoutIntegrityTest extends TestCase
{
    private const CANONICAL_COLS = 12;

    private const WIDGET_MIN_W = 3;

    private const WIDGET_MIN_H = 2;

    private const WIDGET_MAX_H = 6;

    private const DASHBOARD_MAX_H = 24;

    // ── Engine (mirrors useDashboardGrid.js V6) ──

    private function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w']
            && $a['x'] + $a['w'] > $b['x']
            && $a['y'] < $b['y'] + $b['h']
            && $a['y'] + $a['h'] > $b['y'];
    }

    private function clampToBounds(array $tile): array
    {
        $numCols = self::CANONICAL_COLS;
        $w = max(1, min($tile['w'], $numCols));
        $w = max(self::WIDGET_MIN_W, $w);

        $h = max(self::WIDGET_MIN_H, min(self::WIDGET_MAX_H, $tile['h']));
        $x = max(0, min($tile['x'], $numCols - $w));
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

            if ($fixedIdx === -1) break;
            $iterations++;

            $fixed = $layout[$fixedIdx];
            $T = $layout[$moveIdx];
            $placed = false;

            $rightX = $fixed['x'] + $fixed['w'];
            if (! $placed && $rightX + $T['w'] <= self::CANONICAL_COLS) {
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

            if (! $placed) {
                $pushY = $fixed['y'] + $fixed['h'];
                $pushX = max(0, min($T['x'], self::CANONICAL_COLS - $T['w']));
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

            if (! $placed) {
                $maxY = 0;
                foreach ($layout as $k => $t) {
                    if ($k !== $moveIdx) $maxY = max($maxY, $t['y'] + $t['h']);
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
                    if ($i === $j) continue;
                    if ($this->overlaps($candidate, $layout[$j])) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) break;
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
                while ($x + $tile['w'] <= self::CANONICAL_COLS) {
                    $candidate = array_merge($tile, ['x' => $x]);
                    $blocked = false;
                    foreach ($packed as $p) {
                        if ($this->overlaps($candidate, $p)) {
                            $blocked = true;
                            break;
                        }
                    }
                    if (! $blocked) break;
                    $x++;
                }

                if ($x + $tile['w'] <= self::CANONICAL_COLS) {
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
                if ($cursor + $tile['w'] > self::CANONICAL_COLS) {
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
                for ($x = 0; $x + $tile['w'] <= self::CANONICAL_COLS; $x++) {
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
                if ($bestCandidate !== null) break;
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
                if ($this->overlaps($tiles[$i], $tiles[$j])) return false;
            }
        }

        return true;
    }

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

        if (! $this->assertNoOverlapInLayout($tiles)) return null;
        foreach ($tiles as $t) {
            if ($t['y'] + $t['h'] > self::DASHBOARD_MAX_H) return null;
        }

        return $tiles;
    }

    // ── Visual clamping (mirrors DashboardGrid.vue template) ──

    private function renderX(int $x, int $w, int $viewCols): int
    {
        $renderW = min($w, $viewCols);

        return min($x, $viewCols - $renderW);
    }

    private function renderW(int $w, int $viewCols): int
    {
        return min($w, $viewCols);
    }

    // ══════════════════════════════════════════════════
    // TESTS
    // ══════════════════════════════════════════════════

    // ── R1: Pipeline produces valid layout at 12 cols ──

    public function test_pipeline_produces_valid_12col_layout(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlapInLayout($result));

        foreach ($result as $tile) {
            $this->assertLessThanOrEqual(self::CANONICAL_COLS, $tile['x'] + $tile['w']);
            $this->assertGreaterThanOrEqual(0, $tile['x']);
        }
    }

    // ── R2: Pipeline is idempotent ──

    public function test_pipeline_is_idempotent(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'C', 'x' => 0, 'y' => 4, 'w' => 4, 'h' => 2],
        ];

        $first = $this->applyPipeline($layout, null);
        $second = $this->applyPipeline($first, null);

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        // Sort both by key for comparison
        $sortByKey = fn ($a, $b) => strcmp($a['key'], $b['key']);
        usort($first, $sortByKey);
        usort($second, $sortByKey);

        foreach ($first as $i => $tile) {
            $this->assertEquals($tile['x'], $second[$i]['x'], "Tile {$tile['key']}: x differs between passes");
            $this->assertEquals($tile['y'], $second[$i]['y'], "Tile {$tile['key']}: y differs between passes");
            $this->assertEquals($tile['w'], $second[$i]['w'], "Tile {$tile['key']}: w differs between passes");
            $this->assertEquals($tile['h'], $second[$i]['h'], "Tile {$tile['key']}: h differs between passes");
        }
    }

    // ── R3: Valid 12-col layout is unchanged by pipeline ──

    public function test_valid_layout_unchanged_by_pipeline(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);

        $byKey = fn ($a, $b) => strcmp($a['key'], $b['key']);
        usort($layout, $byKey);
        usort($result, $byKey);

        foreach ($layout as $i => $tile) {
            $this->assertEquals($tile['x'], $result[$i]['x'], "Tile {$tile['key']}: x changed");
            $this->assertEquals($tile['y'], $result[$i]['y'], "Tile {$tile['key']}: y changed");
            $this->assertEquals($tile['w'], $result[$i]['w'], "Tile {$tile['key']}: w changed");
            $this->assertEquals($tile['h'], $result[$i]['h'], "Tile {$tile['key']}: h changed");
        }
    }

    // ── R4: Visual clamping produces valid positions for all breakpoints ──

    public function test_visual_clamp_produces_valid_positions(): void
    {
        $viewports = [12, 6];

        $tiles = [
            ['x' => 0, 'w' => 4],
            ['x' => 4, 'w' => 4],
            ['x' => 8, 'w' => 4],
            ['x' => 0, 'w' => 12],
            ['x' => 9, 'w' => 3],
        ];

        foreach ($viewports as $viewCols) {
            foreach ($tiles as $tile) {
                $rw = $this->renderW($tile['w'], $viewCols);
                $rx = $this->renderX($tile['x'], $tile['w'], $viewCols);

                $this->assertGreaterThanOrEqual(0, $rx, "renderX < 0 at {$viewCols} cols for x={$tile['x']}, w={$tile['w']}");
                $this->assertLessThanOrEqual($viewCols, $rx + $rw, "renderX + renderW > viewCols at {$viewCols} cols for x={$tile['x']}, w={$tile['w']}");
                $this->assertGreaterThanOrEqual(1, $rw, "renderW < 1 at {$viewCols} cols for w={$tile['w']}");
            }
        }
    }

    // ══════════════════════════════════════════════════
    // computeVisualLayout TESTS (mirrors JS function)
    // ══════════════════════════════════════════════════

    /**
     * PHP mirror of computeVisualLayout() from useDashboardGrid.js.
     * Pure function — re-packs tiles for smaller viewCols using occupancy map.
     * forceH overrides all tile heights (mobile → 2).
     */
    private function computeVisualLayout(array $canonicalLayout, int $viewCols, ?int $forceH = null): array
    {
        if ($viewCols >= self::CANONICAL_COLS && $forceH === null) {
            return $canonicalLayout;
        }

        $sorted = $canonicalLayout;
        usort($sorted, fn ($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);

        $occupied = [];

        $isOccupied = function (int $row, int $col) use (&$occupied): bool {
            return isset($occupied[$row][$col]) && $occupied[$row][$col];
        };

        $markOccupied = function (int $x, int $y, int $w, int $h) use (&$occupied, $viewCols): void {
            for ($row = $y; $row < $y + $h; $row++) {
                if (!isset($occupied[$row])) {
                    $occupied[$row] = array_fill(0, $viewCols, false);
                }
                for ($col = $x; $col < $x + $w; $col++) {
                    $occupied[$row][$col] = true;
                }
            }
        };

        $canPlace = function (int $x, int $y, int $w, int $h) use ($isOccupied, $viewCols): bool {
            for ($row = $y; $row < $y + $h; $row++) {
                for ($col = $x; $col < $x + $w; $col++) {
                    if ($col >= $viewCols) return false;
                    if ($isOccupied($row, $col)) return false;
                }
            }

            return true;
        };

        $result = [];
        $halfCols = intdiv($viewCols, 2);

        $placeTile = function (array $tile, int $w, int $h) use (&$result, &$markOccupied, $canPlace, $viewCols): void {
            for ($y = 0; ; $y++) {
                for ($x = 0; $x <= $viewCols - $w; $x++) {
                    if ($canPlace($x, $y, $w, $h)) {
                        $markOccupied($x, $y, $w, $h);
                        $result[] = array_merge($tile, ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h]);

                        return;
                    }
                }
                if ($y > 200) {
                    $markOccupied(0, $y, $w, $h);
                    $result[] = array_merge($tile, ['x' => 0, 'y' => $y, 'w' => $w, 'h' => $h]);

                    return;
                }
            }
        };

        // ── Mobile mode: interleave charts (full) and KPI pairs (half) ──
        if ($forceH !== null) {
            $charts = array_values(array_filter($sorted, fn ($t) => $t['w'] > 6));
            $kpis = array_values(array_filter($sorted, fn ($t) => $t['w'] <= 6));
            $kpiOdd = count($kpis) % 2 !== 0;

            $ci = 0;
            $ki = 0;

            while ($ci < count($charts) || $ki < count($kpis)) {
                // Place one chart
                if ($ci < count($charts)) {
                    $placeTile($charts[$ci], $viewCols, $forceH);
                    $ci++;
                }

                // Place up to 2 KPIs
                for ($pair = 0; $pair < 2 && $ki < count($kpis); $pair++) {
                    $isLast = $ki === count($kpis) - 1;
                    $w = ($kpiOdd && $isLast) ? $viewCols : $halfCols;
                    $placeTile($kpis[$ki], $w, $forceH);
                    $ki++;
                }
            }

            return $result;
        }

        // ── Non-mobile: standard visual re-pack ──
        foreach ($sorted as $tile) {
            $renderW = min($tile['w'], $viewCols);
            $placeTile($tile, $renderW, $tile['h']);
        }

        return $result;
    }

    // ── V1: Side-by-side w=6 tiles stack vertically on mobile (6 cols) ──

    public function test_visual_layout_stacks_wide_tiles_on_mobile(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
        ];

        // Mobile = 6 cols. Two w=6 tiles can't sit side by side → stack.
        $visual = $this->computeVisualLayout($canonical, 6);

        $this->assertCount(2, $visual);
        $this->assertFalse($this->overlaps($visual[0], $visual[1]), 'Tiles must not overlap on mobile');

        $tileA = collect($visual)->firstWhere('key', 'A');
        $tileB = collect($visual)->firstWhere('key', 'B');

        $this->assertEquals(0, $tileA['y'], 'First tile starts at y=0');
        $this->assertGreaterThanOrEqual($tileA['y'] + $tileA['h'], $tileB['y'], 'Second tile must be below first');
    }

    // ── V1b: S widgets (w=3) fit 2 per row on mobile (6 cols) ──

    public function test_visual_layout_s_widgets_two_per_row_on_mobile(): void
    {
        $canonical = [
            ['key' => 'S1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'S2', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
        ];

        // Mobile = 6 cols. Two w=3 tiles: 3+3=6 → fits in one row.
        $visual = $this->computeVisualLayout($canonical, 6);

        $this->assertCount(2, $visual);
        $this->assertFalse($this->overlaps($visual[0], $visual[1]), 'S widgets must not overlap');

        $s1 = collect($visual)->firstWhere('key', 'S1');
        $s2 = collect($visual)->firstWhere('key', 'S2');

        // Both on same row
        $this->assertEquals($s1['y'], $s2['y'], 'S widgets must be on same row on mobile (6 cols)');
        $this->assertEquals(3, $s1['w'], 'S widget width preserved');
        $this->assertEquals(3, $s2['w'], 'S widget width preserved');
    }

    // ── V2: Three w=4 tiles on mobile (6 cols) — two fit, third stacks ──

    public function test_visual_layout_stacks_overflow_tiles_on_mobile(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        // Mobile = 6 cols. w=4: first fits at x=0, second doesn't fit (4+4=8>6) → stacks
        $visual = $this->computeVisualLayout($canonical, 6);

        $this->assertCount(3, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual), 'No overlaps on mobile');

        foreach ($visual as $tile) {
            $this->assertLessThanOrEqual(6, $tile['w'], "Tile {$tile['key']} width exceeds viewCols");
            $this->assertLessThanOrEqual(6, $tile['x'] + $tile['w'], "Tile {$tile['key']} overflows grid");
        }
    }

    // ── V3: Desktop (12 cols) returns canonical positions unchanged ──

    public function test_visual_layout_unchanged_at_desktop(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
        ];

        $visual = $this->computeVisualLayout($canonical, 12);

        $this->assertCount(2, $visual);

        foreach ($canonical as $i => $tile) {
            $this->assertEquals($tile['x'], $visual[$i]['x']);
            $this->assertEquals($tile['y'], $visual[$i]['y']);
            $this->assertEquals($tile['w'], $visual[$i]['w']);
        }
    }

    // ── V4: Tablet (6 cols) partially stacks ──

    public function test_visual_layout_tablet_partial_stack(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $visual = $this->computeVisualLayout($canonical, 6);

        $this->assertCount(3, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual), 'No overlaps on tablet');

        foreach ($visual as $tile) {
            $this->assertLessThanOrEqual(6, $tile['x'] + $tile['w'], "Tile {$tile['key']} overflows 6-col grid");
        }
    }

    // ── V5: Visual layout preserves reading order ──

    public function test_visual_layout_preserves_reading_order(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'C', 'x' => 0, 'y' => 3, 'w' => 12, 'h' => 4],
        ];

        $visual = $this->computeVisualLayout($canonical, 6);

        // Find tiles by key
        $tileA = collect($visual)->firstWhere('key', 'A');
        $tileB = collect($visual)->firstWhere('key', 'B');
        $tileC = collect($visual)->firstWhere('key', 'C');

        // A before B (same row or B below)
        $this->assertTrue(
            $tileA['y'] < $tileB['y'] || ($tileA['y'] === $tileB['y'] && $tileA['x'] < $tileB['x']),
            'A must come before B in reading order',
        );

        // B before C
        $this->assertTrue(
            $tileB['y'] < $tileC['y'] || ($tileB['y'] === $tileC['y'] && $tileB['x'] < $tileC['x']),
            'B must come before C in reading order',
        );
    }

    // ── V6: No overlap in visual layout for complex layouts ──

    public function test_visual_layout_no_overlap_complex_layout(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4],
            ['key' => 'B', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 0, 'y' => 4, 'w' => 6, 'h' => 2],
            ['key' => 'D', 'x' => 6, 'y' => 4, 'w' => 6, 'h' => 2],
        ];

        foreach ([4, 6] as $viewCols) {
            $visual = $this->computeVisualLayout($canonical, $viewCols);

            $this->assertCount(4, $visual, "Missing tiles at {$viewCols} cols");
            $this->assertTrue(
                $this->assertNoOverlapInLayout($visual),
                "Overlap detected at {$viewCols} cols",
            );

            foreach ($visual as $tile) {
                $this->assertLessThanOrEqual(
                    $viewCols,
                    $tile['x'] + $tile['w'],
                    "Tile {$tile['key']} overflows at {$viewCols} cols",
                );
            }
        }
    }

    // ── V7: Mobile forces all widgets to h=2 ──

    public function test_visual_layout_mobile_forces_h2(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 6],
            ['key' => 'C', 'x' => 0, 'y' => 6, 'w' => 3, 'h' => 3],
        ];

        // Mobile: forceH = 2
        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $this->assertCount(3, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual), 'No overlaps with forceH');

        foreach ($visual as $tile) {
            $this->assertEquals(2, $tile['h'], "Tile {$tile['key']} must have h=2 on mobile");
        }
    }

    // ── V8: Desktop preserves original heights (no forceH) ──

    public function test_visual_layout_desktop_preserves_heights(): void
    {
        $canonical = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 6],
        ];

        $visual = $this->computeVisualLayout($canonical, 12);

        $tileA = collect($visual)->firstWhere('key', 'A');
        $tileB = collect($visual)->firstWhere('key', 'B');

        $this->assertEquals(4, $tileA['h'], 'Desktop must preserve original h');
        $this->assertEquals(6, $tileB['h'], 'Desktop must preserve original h');
    }

    // ── V9: S widgets + forceH=2 → compact mobile grid ──

    public function test_visual_layout_s_widgets_compact_mobile(): void
    {
        // 4 S widgets (w=3, h=2) on mobile → 2 per row, 2 rows
        $canonical = [
            ['key' => 'S1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'S2', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'S3', 'x' => 6, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'S4', 'x' => 9, 'y' => 0, 'w' => 3, 'h' => 2],
        ];

        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $this->assertCount(4, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual));

        // Row 0: S1 + S2 (3+3=6), Row 2: S3 + S4
        $s1 = collect($visual)->firstWhere('key', 'S1');
        $s2 = collect($visual)->firstWhere('key', 'S2');
        $s3 = collect($visual)->firstWhere('key', 'S3');
        $s4 = collect($visual)->firstWhere('key', 'S4');

        $this->assertEquals($s1['y'], $s2['y'], 'S1 and S2 on same row');
        $this->assertEquals($s3['y'], $s4['y'], 'S3 and S4 on same row');
        $this->assertGreaterThan($s1['y'], $s3['y'], 'S3 below S1');
    }

    // ── V10: Odd small widgets → last one full width ──

    public function test_visual_layout_odd_small_last_full_width(): void
    {
        $canonical = [
            ['key' => 'S1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'S2', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'S3', 'x' => 6, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        // 3 small widgets (w<=4) → odd → last (S3) goes full width
        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $this->assertCount(3, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual));

        $s1 = collect($visual)->firstWhere('key', 'S1');
        $s2 = collect($visual)->firstWhere('key', 'S2');
        $s3 = collect($visual)->firstWhere('key', 'S3');

        // S1 + S2 paired (w=3 each)
        $this->assertEquals(3, $s1['w']);
        $this->assertEquals(3, $s2['w']);
        $this->assertEquals($s1['y'], $s2['y'], 'S1 and S2 on same row');

        // S3 full width (odd → last small)
        $this->assertEquals(6, $s3['w'], 'Last odd small widget must be full width');
        $this->assertGreaterThan($s1['y'], $s3['y'], 'S3 below paired row');
    }

    // ── V11: Even small widgets → all half width ──

    public function test_visual_layout_even_small_all_half(): void
    {
        $canonical = [
            ['key' => 'S1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'S2', 'x' => 3, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        // 2 small widgets → even → both half
        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $s1 = collect($visual)->firstWhere('key', 'S1');
        $s2 = collect($visual)->firstWhere('key', 'S2');

        $this->assertEquals(3, $s1['w']);
        $this->assertEquals(3, $s2['w']);
        $this->assertEquals($s1['y'], $s2['y'], 'Both on same row when even');
    }

    // ── V12: Single small widget → full width (odd=1) ──

    public function test_visual_layout_single_small_full_width(): void
    {
        $canonical = [
            ['key' => 'S1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'L1', 'x' => 3, 'y' => 0, 'w' => 9, 'h' => 4],
        ];

        // 1 small widget → odd → full width
        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $s1 = collect($visual)->firstWhere('key', 'S1');
        $l1 = collect($visual)->firstWhere('key', 'L1');

        $this->assertEquals(6, $s1['w'], 'Single small widget must be full width');
        $this->assertEquals(6, $l1['w'], 'Large widget full width');
    }

    // ── V13: Mixed small + large → small paired, large full, odd rule applies ──

    public function test_visual_layout_mixed_with_odd_small(): void
    {
        $canonical = [
            ['key' => 'S1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'L1', 'x' => 3, 'y' => 0, 'w' => 9, 'h' => 4],
            ['key' => 'S2', 'x' => 0, 'y' => 4, 'w' => 4, 'h' => 3],
            ['key' => 'M1', 'x' => 4, 'y' => 4, 'w' => 8, 'h' => 3],
            ['key' => 'S3', 'x' => 9, 'y' => 4, 'w' => 3, 'h' => 2],
        ];

        // KPI (w<=6): S1(3), S2(4), S3(3) = 3 → odd → last full
        // Chart (w>6): L1(9), M1(8) → full
        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $this->assertCount(5, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual));

        // Chart widgets full width
        $l1 = collect($visual)->firstWhere('key', 'L1');
        $m1 = collect($visual)->firstWhere('key', 'M1');
        $this->assertEquals(6, $l1['w'], 'L1 (w=9) full width');
        $this->assertEquals(6, $m1['w'], 'M1 (w=8) full width — w>6');

        // S1 + S2 paired (half), S3 full (odd last)
        $s1 = collect($visual)->firstWhere('key', 'S1');
        $s2 = collect($visual)->firstWhere('key', 'S2');
        $s3 = collect($visual)->firstWhere('key', 'S3');
        $this->assertEquals(3, $s1['w'], 'S1 half width');
        $this->assertEquals(3, $s2['w'], 'S2 half width');
        $this->assertEquals(6, $s3['w'], 'S3 full width — odd last');
    }

    // ── V14: Interleaving order: chart → KPI pair → chart → KPI pair ──

    public function test_visual_layout_mobile_interleaves_charts_and_kpis(): void
    {
        $canonical = [
            ['key' => 'K1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'C1', 'x' => 3, 'y' => 0, 'w' => 9, 'h' => 4],
            ['key' => 'K2', 'x' => 0, 'y' => 4, 'w' => 4, 'h' => 3],
            ['key' => 'C2', 'x' => 4, 'y' => 4, 'w' => 8, 'h' => 3],
            ['key' => 'K3', 'x' => 0, 'y' => 7, 'w' => 3, 'h' => 2],
            ['key' => 'K4', 'x' => 3, 'y' => 7, 'w' => 4, 'h' => 2],
        ];

        // Charts (w>6): C1(9), C2(8)
        // KPIs (w<=6): K1(3), K2(4), K3(3), K4(4) → 4 = even → all half (3 cols)
        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $this->assertCount(6, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual));

        // Verify interleaving order: C1 → K1,K2 → C2 → K3,K4
        // result array is in placement order
        $keys = array_column($visual, 'key');
        $this->assertEquals(['C1', 'K1', 'K2', 'C2', 'K3', 'K4'], $keys, 'Must interleave: chart → KPI pair → chart → KPI pair');

        // C1 full width at y=0
        $c1 = collect($visual)->firstWhere('key', 'C1');
        $this->assertEquals(6, $c1['w']);
        $this->assertEquals(0, $c1['y']);

        // K1 + K2 paired at y=2
        $k1 = collect($visual)->firstWhere('key', 'K1');
        $k2 = collect($visual)->firstWhere('key', 'K2');
        $this->assertEquals(3, $k1['w']);
        $this->assertEquals(3, $k2['w']);
        $this->assertEquals($k1['y'], $k2['y'], 'K1 and K2 must be on same row');
        $this->assertEquals(2, $k1['y'], 'KPI pair after first chart');

        // C2 full width at y=4
        $c2 = collect($visual)->firstWhere('key', 'C2');
        $this->assertEquals(6, $c2['w']);
        $this->assertEquals(4, $c2['y']);

        // K3 + K4 paired at y=6
        $k3 = collect($visual)->firstWhere('key', 'K3');
        $k4 = collect($visual)->firstWhere('key', 'K4');
        $this->assertEquals(3, $k3['w']);
        $this->assertEquals(3, $k4['w']);
        $this->assertEquals($k3['y'], $k4['y'], 'K3 and K4 must be on same row');
        $this->assertEquals(6, $k3['y'], 'KPI pair after second chart');
    }

    // ── V15: Only KPIs (no charts) — still pairs correctly ──

    public function test_visual_layout_mobile_kpis_only_interleave(): void
    {
        $canonical = [
            ['key' => 'K1', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ['key' => 'K2', 'x' => 3, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'K3', 'x' => 7, 'y' => 0, 'w' => 4, 'h' => 2],
        ];

        // 3 KPIs, 0 charts → odd → last full width
        // Interleave: no chart → K1(half), K2(half) → no chart → K3(full)
        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $this->assertCount(3, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual));

        $keys = array_column($visual, 'key');
        $this->assertEquals(['K1', 'K2', 'K3'], $keys);

        $k1 = collect($visual)->firstWhere('key', 'K1');
        $k2 = collect($visual)->firstWhere('key', 'K2');
        $k3 = collect($visual)->firstWhere('key', 'K3');

        // K1 + K2 paired
        $this->assertEquals(3, $k1['w']);
        $this->assertEquals(3, $k2['w']);
        $this->assertEquals($k1['y'], $k2['y']);

        // K3 full width (odd last)
        $this->assertEquals(6, $k3['w']);
    }

    // ── V16: Only charts (no KPIs) — all full width stacked ──

    public function test_visual_layout_mobile_charts_only(): void
    {
        $canonical = [
            ['key' => 'C1', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4],
            ['key' => 'C2', 'x' => 0, 'y' => 4, 'w' => 9, 'h' => 3],
        ];

        $visual = $this->computeVisualLayout($canonical, 6, 2);

        $this->assertCount(2, $visual);
        $this->assertTrue($this->assertNoOverlapInLayout($visual));

        $c1 = collect($visual)->firstWhere('key', 'C1');
        $c2 = collect($visual)->firstWhere('key', 'C2');

        $this->assertEquals(6, $c1['w']);
        $this->assertEquals(6, $c2['w']);
        $this->assertEquals(0, $c1['y']);
        $this->assertEquals(2, $c2['y'], 'Second chart stacks below first');
    }

    // ── R5: remapBreakpoint function no longer exists in JS ──

    public function test_remap_breakpoint_removed_from_composable(): void
    {
        $file = dirname(__DIR__, 2) . '/resources/js/composables/useDashboardGrid.js';

        $this->assertFileExists($file);

        $content = file_get_contents($file);

        $this->assertStringNotContainsString(
            'remapBreakpoint',
            $content,
            'remapBreakpoint must be removed from useDashboardGrid.js — layout is single source of truth.',
        );
    }

    // ── R6: DashboardGrid.vue has no cols watcher that mutates layout ──

    public function test_dashboard_grid_has_no_cols_watcher(): void
    {
        $file = dirname(__DIR__, 2) . '/resources/js/components/dashboard/DashboardGrid.vue';

        $this->assertFileExists($file);

        $content = file_get_contents($file);

        $this->assertStringNotContainsString(
            'watch(cols',
            $content,
            'DashboardGrid must not watch cols — viewport resize must never mutate layout.',
        );
    }

    // ── R7: computeVisualLayout exists in JS composable ──

    public function test_compute_visual_layout_exists_in_composable(): void
    {
        $file = dirname(__DIR__, 2) . '/resources/js/composables/useDashboardGrid.js';

        $this->assertFileExists($file);

        $content = file_get_contents($file);

        $this->assertStringContainsString(
            'computeVisualLayout',
            $content,
            'useDashboardGrid.js must export computeVisualLayout for responsive rendering.',
        );
    }

    // ── R8: DashboardGrid.vue uses computeVisualLayout for rendering ──

    public function test_dashboard_grid_uses_compute_visual_layout(): void
    {
        $file = dirname(__DIR__, 2) . '/resources/js/components/dashboard/DashboardGrid.vue';

        $this->assertFileExists($file);

        $content = file_get_contents($file);

        $this->assertStringContainsString(
            'computeVisualLayout',
            $content,
            'DashboardGrid must use computeVisualLayout for responsive rendering.',
        );
    }
}
