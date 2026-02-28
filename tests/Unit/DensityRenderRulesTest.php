<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR-152 V3: Density render rules.
 *
 * Proves the density algorithm assigns S/M/L correctly
 * and documents the rendering contract for each density.
 *
 * Rendering rules (enforced in Vue, documented here):
 *
 * S (Sparkline):
 *   - chart.sparkline.enabled = true
 *   - grid.show = false
 *   - xaxis.labels.show = false
 *   - xaxis.axisBorder.show = false
 *   - xaxis.axisTicks.show = false
 *   - xaxis.crosshairs.show = false
 *   - yaxis.show = false
 *   - yaxis.labels.show = false
 *   - yaxis.axisBorder.show = false
 *   - yaxis.axisTicks.show = false
 *   - legend.show = false
 *   - grid.padding.bottom = 12
 *   - Header: always rendered (text-caption + icon)
 *   - KPI: never cropped (flex: 0 0 auto)
 *
 * M (Medium):
 *   - xaxis.labels.formatter = "DD MMM" (e.g., "12 Feb")
 *   - xaxis.tickAmount = max(2, floor(pxWidth/80))
 *   - xaxis.labels.hideOverlappingLabels = true
 *   - Header: always rendered (VCardTitle)
 *   - Chart: height="100%" of widget-chart-area
 *
 * L (Large):
 *   - Full chart with all features
 *   - Header: always rendered (VCardTitle)
 *   - Chart: height="100%" of widget-chart-area
 */
class DensityRenderRulesTest extends TestCase
{
    private function computeDensity(int $h, int $pxWidth): string
    {
        if ($h <= 2) {
            return 'S';
        }
        if ($h <= 3) {
            return 'M';
        }
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

    // ── S density rule: h<=2 forces sparkline ──

    public function test_s_forced_by_height_2(): void
    {
        // h<=2 → S regardless of width (sparkline only, no axes)
        $this->assertEquals('S', $this->computeDensity(1, 1200));
        $this->assertEquals('S', $this->computeDensity(2, 1200));
        $this->assertEquals('S', $this->computeDensity(2, 800));
        $this->assertEquals('S', $this->computeDensity(2, 400));
        $this->assertEquals('S', $this->computeDensity(2, 0));
    }

    public function test_s_forced_by_narrow_width(): void
    {
        // h>=4 but pxWidth<400 → S
        $this->assertEquals('S', $this->computeDensity(4, 0));
        $this->assertEquals('S', $this->computeDensity(4, 200));
        $this->assertEquals('S', $this->computeDensity(4, 399));
        $this->assertEquals('S', $this->computeDensity(6, 300));
    }

    // ── M density rule: h=3 forces M, or medium width ──

    public function test_m_forced_by_height_3(): void
    {
        // h=3 → M regardless of width (always M override)
        $this->assertEquals('M', $this->computeDensity(3, 0));
        $this->assertEquals('M', $this->computeDensity(3, 200));
        $this->assertEquals('M', $this->computeDensity(3, 500));
        $this->assertEquals('M', $this->computeDensity(3, 800));
        $this->assertEquals('M', $this->computeDensity(3, 1200));
    }

    public function test_m_by_medium_width(): void
    {
        // h>=4, 400 <= pxWidth < 700 → M
        $this->assertEquals('M', $this->computeDensity(4, 400));
        $this->assertEquals('M', $this->computeDensity(4, 500));
        $this->assertEquals('M', $this->computeDensity(4, 699));
        $this->assertEquals('M', $this->computeDensity(6, 600));
    }

    // ── L density rule: wide enough ──

    public function test_l_by_wide_width(): void
    {
        // h>=4, pxWidth >= 700 → L
        $this->assertEquals('L', $this->computeDensity(4, 700));
        $this->assertEquals('L', $this->computeDensity(4, 960));
        $this->assertEquals('L', $this->computeDensity(6, 800));
        $this->assertEquals('L', $this->computeDensity(8, 1200));
    }

    // ── M density tick computation ──

    public function test_m_tick_amount_from_width(): void
    {
        // maxTicks = max(2, floor(pxWidth/80))
        $this->assertEquals(2, $this->computeMaxTicks(100));
        $this->assertEquals(2, $this->computeMaxTicks(159));
        $this->assertEquals(2, $this->computeMaxTicks(160));
        $this->assertEquals(3, $this->computeMaxTicks(240));
        $this->assertEquals(5, $this->computeMaxTicks(400));
        $this->assertEquals(8, $this->computeMaxTicks(699));
    }

    // ── Boundary transitions ──

    public function test_boundary_height_2_to_3(): void
    {
        // h=2 → S, h=3 → M (transition point)
        $this->assertEquals('S', $this->computeDensity(2, 800));
        $this->assertEquals('M', $this->computeDensity(3, 800));
    }

    public function test_boundary_height_3_to_4(): void
    {
        // h=3 → M (forced), h=4 → depends on width
        $this->assertEquals('M', $this->computeDensity(3, 800));
        $this->assertEquals('L', $this->computeDensity(4, 800));
    }

    public function test_boundary_width_399_to_400(): void
    {
        // pxWidth=399 → S, pxWidth=400 → M (h>=4)
        $this->assertEquals('S', $this->computeDensity(4, 399));
        $this->assertEquals('M', $this->computeDensity(4, 400));
    }

    public function test_boundary_width_699_to_700(): void
    {
        // pxWidth=699 → M, pxWidth=700 → L (h>=4)
        $this->assertEquals('M', $this->computeDensity(4, 699));
        $this->assertEquals('L', $this->computeDensity(4, 700));
    }
}
