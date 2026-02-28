<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR-152 V2: Widget density + chart tick computation unit tests.
 *
 * Tests the density algorithm (height + pixel width):
 *   - h <= 2 → always S (height override, sparkline only)
 *   - h <= 3 → always M (height override)
 *   - pxWidth < 400 → S
 *   - pxWidth < 700 → M
 *   - else → L
 * Tests the maxTicks algorithm: max(2, floor(pxWidth / 80)).
 * These mirror the frontend computeDensity() and maxTicks computed.
 */
class WidgetDensityTest extends TestCase
{
    private function computeDensity(int $h, int $pxWidth): string
    {
        // Height overrides: h <= 2 forces S, h <= 3 forces M
        if ($h <= 2) {
            return 'S';
        }
        if ($h <= 3) {
            return 'M';
        }

        // Pixel width thresholds (h >= 4)
        if ($pxWidth < 400) {
            return 'S';
        }
        if ($pxWidth < 700) {
            return 'M';
        }

        return 'L';
    }

    private function computeMaxTicks(int $pxWidth): int
    {
        return max(2, (int) floor($pxWidth / 80));
    }

    public function test_height_2_forces_s_regardless_of_width(): void
    {
        // h <= 2 always forces S — no matter how wide
        $this->assertEquals('S', $this->computeDensity(1, 0));
        $this->assertEquals('S', $this->computeDensity(1, 800));
        $this->assertEquals('S', $this->computeDensity(2, 0));
        $this->assertEquals('S', $this->computeDensity(2, 300));
        $this->assertEquals('S', $this->computeDensity(2, 500));
        $this->assertEquals('S', $this->computeDensity(2, 900));
        $this->assertEquals('S', $this->computeDensity(2, 1200));
    }

    public function test_height_3_forces_m_regardless_of_width(): void
    {
        // h = 3 always forces M — no matter how wide or narrow
        $this->assertEquals('M', $this->computeDensity(3, 0));
        $this->assertEquals('M', $this->computeDensity(3, 200));
        $this->assertEquals('M', $this->computeDensity(3, 500));
        $this->assertEquals('M', $this->computeDensity(3, 800));
        $this->assertEquals('M', $this->computeDensity(3, 1200));
    }

    public function test_large_tile_returns_l(): void
    {
        // h >= 4, pxWidth >= 700 → L
        $this->assertEquals('L', $this->computeDensity(4, 700));
        $this->assertEquals('L', $this->computeDensity(4, 960));
        $this->assertEquals('L', $this->computeDensity(6, 800));
        $this->assertEquals('L', $this->computeDensity(8, 1200));
    }

    public function test_medium_tile_returns_m(): void
    {
        // h >= 4, 400 <= pxWidth < 700 → M
        $this->assertEquals('M', $this->computeDensity(4, 400));
        $this->assertEquals('M', $this->computeDensity(4, 500));
        $this->assertEquals('M', $this->computeDensity(4, 699));
        $this->assertEquals('M', $this->computeDensity(6, 600));
    }

    public function test_narrow_tile_returns_s(): void
    {
        // h >= 4, pxWidth < 400 → S
        $this->assertEquals('S', $this->computeDensity(4, 0));
        $this->assertEquals('S', $this->computeDensity(4, 200));
        $this->assertEquals('S', $this->computeDensity(4, 399));
        $this->assertEquals('S', $this->computeDensity(6, 300));
    }

    public function test_boundary_values(): void
    {
        // h=2 → S (boundary between S-override and M-override)
        $this->assertEquals('S', $this->computeDensity(2, 900));
        // h=3 → M (boundary between M-override and px-based)
        $this->assertEquals('M', $this->computeDensity(3, 900));
        // h=4 — now pixel-based
        $this->assertEquals('S', $this->computeDensity(4, 399));
        $this->assertEquals('M', $this->computeDensity(4, 400));
        $this->assertEquals('M', $this->computeDensity(4, 699));
        $this->assertEquals('L', $this->computeDensity(4, 700));
    }

    public function test_max_ticks_computation(): void
    {
        // Very narrow: minimum 2 ticks
        $this->assertEquals(2, $this->computeMaxTicks(100));
        $this->assertEquals(2, $this->computeMaxTicks(159));

        // Medium widths
        $this->assertEquals(2, $this->computeMaxTicks(160));
        $this->assertEquals(3, $this->computeMaxTicks(240));
        $this->assertEquals(4, $this->computeMaxTicks(320));
        $this->assertEquals(5, $this->computeMaxTicks(400));

        // Large widths
        $this->assertEquals(10, $this->computeMaxTicks(800));
        $this->assertEquals(12, $this->computeMaxTicks(960));

        // Edge: 0 width → minimum 2
        $this->assertEquals(2, $this->computeMaxTicks(0));
    }
}
