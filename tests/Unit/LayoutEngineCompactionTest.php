<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR-152 V3: Layout engine — ZERO HOLES invariant.
 *
 * Proves that compactLayout gravity pulls all tiles up:
 * after any operation, no tile has empty space below it
 * that could be occupied without overlap.
 */
class LayoutEngineCompactionTest extends TestCase
{
    private int $cols = 12;

    private function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w']
            && $a['x'] + $a['w'] > $b['x']
            && $a['y'] < $b['y'] + $b['h']
            && $a['y'] + $a['h'] > $b['y'];
    }

    private function compactLayout(array $tiles): array
    {
        $layout = array_map(fn($t) => $t, $tiles);
        usort($layout, fn($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);

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

    /**
     * Assert that no tile can be moved up without overlap (fully compacted).
     */
    private function assertFullyCompacted(array $layout): void
    {
        foreach ($layout as $i => $tile) {
            if ($tile['y'] === 0) {
                continue;
            }
            // Check if moving up by 1 would overlap
            $candidate = array_merge($tile, ['y' => $tile['y'] - 1]);
            $blocked = false;
            foreach ($layout as $j => $other) {
                if ($i === $j) {
                    continue;
                }
                if ($this->overlaps($candidate, $other)) {
                    $blocked = true;
                    break;
                }
            }
            $this->assertTrue($blocked, "Tile {$tile['key']} at y={$tile['y']} can be moved up — not compacted");
        }
    }

    // ── Tests ──

    public function test_tiles_with_gap_compact_to_zero(): void
    {
        // Tiles at y=5 with nothing above → should compact to y=0
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 5, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 5, 'w' => 4, 'h' => 3],
        ];

        $result = $this->compactLayout($layout);

        $a = collect($result)->firstWhere('key', 'A');
        $b = collect($result)->firstWhere('key', 'B');
        $this->assertEquals(0, $a['y']);
        $this->assertEquals(0, $b['y']);
    }

    public function test_stacked_tiles_compact_removes_middle_gap(): void
    {
        // A at y=0, B at y=8 (gap of 5 rows). B should compact up to y=3
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 0, 'y' => 8, 'w' => 4, 'h' => 3],
        ];

        $result = $this->compactLayout($layout);

        $a = collect($result)->firstWhere('key', 'A');
        $b = collect($result)->firstWhere('key', 'B');
        $this->assertEquals(0, $a['y']);
        $this->assertEquals(3, $b['y']); // right below A
        $this->assertFullyCompacted($result);
    }

    public function test_remove_tile_others_compact_up(): void
    {
        // Remove middle tile, remaining tiles should compact
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 3],
            // B was here at y=3 (removed)
            ['key' => 'C', 'x' => 0, 'y' => 6, 'w' => 12, 'h' => 3],
        ];

        $result = $this->compactLayout($layout);

        $c = collect($result)->firstWhere('key', 'C');
        $this->assertEquals(3, $c['y']); // compacted up to right below A
        $this->assertFullyCompacted($result);
    }

    public function test_side_by_side_tiles_compact_independently(): void
    {
        // A(0,0,6,2) B(6,0,6,4) C(0,10,6,2) — C should compact to y=2 (below A)
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 2],
            ['key' => 'B', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 4],
            ['key' => 'C', 'x' => 0, 'y' => 10, 'w' => 6, 'h' => 2],
        ];

        $result = $this->compactLayout($layout);

        $c = collect($result)->firstWhere('key', 'C');
        $this->assertEquals(2, $c['y']); // below A, not blocked by B (different x range)
        $this->assertFullyCompacted($result);
    }

    public function test_already_compact_layout_unchanged(): void
    {
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'B', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'C', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
            ['key' => 'D', 'x' => 0, 'y' => 3, 'w' => 6, 'h' => 2],
        ];

        $result = $this->compactLayout($layout);

        // All tiles at same position
        foreach (['A', 'B', 'C', 'D'] as $key) {
            $orig = collect($layout)->firstWhere('key', $key);
            $comp = collect($result)->firstWhere('key', $key);
            $this->assertEquals($orig['y'], $comp['y'], "$key y changed");
        }
        $this->assertFullyCompacted($result);
    }

    public function test_many_tiles_all_compact(): void
    {
        // 6 tiles at random high y positions, all w=4
        $layout = [
            ['key' => 'A', 'x' => 0, 'y' => 10, 'w' => 4, 'h' => 2],
            ['key' => 'B', 'x' => 4, 'y' => 20, 'w' => 4, 'h' => 2],
            ['key' => 'C', 'x' => 8, 'y' => 15, 'w' => 4, 'h' => 2],
            ['key' => 'D', 'x' => 0, 'y' => 30, 'w' => 4, 'h' => 2],
            ['key' => 'E', 'x' => 4, 'y' => 25, 'w' => 4, 'h' => 2],
            ['key' => 'F', 'x' => 8, 'y' => 35, 'w' => 4, 'h' => 2],
        ];

        $result = $this->compactLayout($layout);

        // All should compact to first 2 rows (6 tiles of w=4 in 12-col → 2 rows)
        foreach ($result as $tile) {
            $this->assertLessThanOrEqual(2, $tile['y'], "Tile {$tile['key']} at y={$tile['y']} — expected ≤ 2");
        }
        $this->assertFullyCompacted($result);
    }
}
