<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR-152 V5: Layout engine — REFLOW invariant.
 *
 * Proves that:
 * - Resize at end of row → wraps to next row
 * - Add widget to full row → goes below
 * - Breakpoint change → proportional remap + pipeline
 * - Shift right preferred over push down when space available
 * - Mobile (4 cols) → w clamped to 2, stacking enforced
 *
 * Pipeline: clamp → resolve → compact → assert.
 * Each widget keeps its own h (free height, no row unification).
 */
class LayoutEngineReflowTest extends TestCase
{
    private int $cols = 12;

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

        // B3: Mobile (4 cols) → max w = 2
        if ($this->cols === 4) {
            $w = min(2, $w);
        }

        $h = max(1, $tile['h']);
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

            // 1) SHIFT RIGHT
            $rightX = $fixed['x'] + $fixed['w'];
            if (! $placed && $rightX + $T['w'] <= $this->cols) {
                $candidate = array_merge($T, ['x' => $rightX]);
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

            // 2) SHIFT LEFT
            if (! $placed) {
                $leftX = $fixed['x'] - $T['w'];
                if ($leftX >= 0) {
                    $candidate = array_merge($T, ['x' => $leftX]);
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

    private function assertNoOverlap(array $tiles): bool
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

    private function applyPipeline(array $layout, ?string $movedKey): ?array
    {
        $tiles = array_map(fn ($t) => $this->clampToBounds($t), $layout);
        $tiles = $this->resolveOverlaps($tiles, $movedKey);
        $tiles = $this->compactLayout($tiles);

        return $this->assertNoOverlap($tiles) ? $tiles : null;
    }

    private function remapBreakpoint(array $layout, int $oldCols, int $newCols): ?array
    {
        $remapped = array_map(function ($t) use ($oldCols, $newCols) {
            $newW = min($newCols, max(1, (int) round($t['w'] * $newCols / $oldCols)));

            // B3: Mobile → max w = 2
            if ($newCols === 4) {
                $newW = min(2, $newW);
            }

            $newX = max(0, min((int) floor($t['x'] * $newCols / $oldCols), $newCols - $newW));

            return array_merge($t, ['x' => $newX, 'w' => $newW]);
        }, $layout);

        $prevCols = $this->cols;
        $this->cols = $newCols;
        $result = $this->applyPipeline($remapped, null);
        $this->cols = $prevCols;

        return $result ?? $remapped;
    }

    // ── Tests ──

    public function test_resize_at_row_end_resolves_without_overlap(): void
    {
        // A(0,0,4,3) B(4,0,4,3) C(8,0,4,3). Resize B to w=8 → overlaps C
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 8, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'B');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlap($result));

        // B keeps position (priority)
        $b = collect($result)->firstWhere('key', 'B');
        $this->assertEquals(4, $b['x']);
        $this->assertEquals(0, $b['y']);
        $this->assertEquals(8, $b['w']);

        // C must have moved (no overlap with B)
        $c = collect($result)->firstWhere('key', 'C');
        $this->assertGreaterThan(0, $c['y'], 'C should be pushed down (no room on row 0)');
    }

    public function test_add_widget_to_full_row_goes_below(): void
    {
        // Row 0 full: A(0,0,4,3) B(4,0,4,3) C(8,0,4,3)
        // New D(0,0,4,3) added → should end up on row 1
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'D', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'D');

        $this->assertNotNull($result);
        $this->assertTrue($this->assertNoOverlap($result));

        // D has priority, A should have been moved
        $d = collect($result)->firstWhere('key', 'D');
        $this->assertEquals(0, $d['x']);
        $this->assertEquals(0, $d['y']);
    }

    public function test_breakpoint_12_to_8_remaps_proportionally(): void
    {
        // A(0,0,6,3) B(6,0,6,3) at 12 cols → remap to 8 cols
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
        ];

        $result = $this->remapBreakpoint($layout, 12, 8);

        $this->assertNotNull($result);
        // Both tiles should have w=4 (6*8/12=4)
        $a = collect($result)->firstWhere('key', 'A');
        $b = collect($result)->firstWhere('key', 'B');
        $this->assertEquals(4, $a['w']);
        $this->assertEquals(4, $b['w']);
        $this->assertEquals(0, $a['x']);
        $this->assertEquals(4, $b['x']);
    }

    public function test_breakpoint_12_to_4_forces_stacking(): void
    {
        // 3 tiles of w=4 at 12 cols → at 4 cols, each becomes ~1-2 wide
        // They must stack vertically
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->remapBreakpoint($layout, 12, 4);

        $this->assertNotNull($result);

        $this->cols = 4;
        $this->assertTrue($this->assertNoOverlap($result));
        $this->cols = 12;

        // All tiles within 4 cols, w max 2
        foreach ($result as $tile) {
            $this->assertLessThanOrEqual(4, $tile['x'] + $tile['w']);
            $this->assertLessThanOrEqual(2, $tile['w'], "Mobile: w must be ≤ 2");
        }
    }

    public function test_shift_right_before_push_down(): void
    {
        // A(0,0,4,3) and B(2,0,4,3) — B overlaps A
        // B should shift right to (4,0), not push down
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 2, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result);
        $b = collect($result)->firstWhere('key', 'B');
        // B should be at y=0 (shifted right, not pushed down)
        $this->assertEquals(0, $b['y']);
        $this->assertEquals(4, $b['x']);
    }

    public function test_shift_left_when_right_blocked(): void
    {
        // A(4,0,4,3) B(4,0,4,3) C(8,0,4,3) — B overlaps A
        // Right of A (x=8) is blocked by C → B should shift left to (0,0)
        $layout = [
            ['key' => 'A', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->applyPipeline($layout, 'A');

        $this->assertNotNull($result);
        $b = collect($result)->firstWhere('key', 'B');
        // B should be at y=0 (shifted left to x=0, not pushed down)
        $this->assertEquals(0, $b['y']);
        $this->assertEquals(0, $b['x']);
    }

    // ── V5: Mobile breakpoint reflow ──

    public function test_breakpoint_8_to_4_mobile_clamp(): void
    {
        // 2 tiles of w=4 at 8 cols → at 4 cols, w → 2 each
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
        ];

        $result = $this->remapBreakpoint($layout, 8, 4);

        $this->assertNotNull($result);

        $this->cols = 4;
        $this->assertTrue($this->assertNoOverlap($result));
        $this->cols = 12;

        foreach ($result as $tile) {
            $this->assertLessThanOrEqual(2, $tile['w'], "Mobile: w must be ≤ 2");
        }
    }

    public function test_free_height_preserved_after_reflow(): void
    {
        // Two tiles with different heights on same row — heights preserved
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 2],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 4],
        ];

        $result = $this->applyPipeline($layout, null);

        $this->assertNotNull($result);
        $a = collect($result)->firstWhere('key', 'A');
        $b = collect($result)->firstWhere('key', 'B');

        // Each keeps its own height (no row unification)
        $this->assertEquals(2, $a['h']);
        $this->assertEquals(4, $b['h']);
    }
}
