<?php

namespace Tests\Unit;

use App\Core\Billing\PaymentRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Modules\Dashboard\LayoutPacker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-149 D4e.3: LayoutPacker unit tests.
 */
class LayoutPackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PlatformSeeder::class);
        ModuleRegistry::sync();
        PaymentRegistry::boot();
        DashboardWidgetRegistry::clearCache();
        DashboardWidgetRegistry::boot();
    }

    public function test_empty_grid_packs_correctly(): void
    {
        $newWidgets = [
            ['key' => 'billing.revenue_trend', 'scope' => 'global', 'config' => []],
            ['key' => 'billing.refund_ratio', 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutPacker::pack([], $newWidgets);

        $this->assertCount(2, $result['packed']);
        $this->assertEmpty($result['pending']);

        // First widget (w=8) at (0,0)
        $this->assertEquals(0, $result['packed'][0]['x']);
        $this->assertEquals(0, $result['packed'][0]['y']);
        $this->assertEquals(8, $result['packed'][0]['w']);

        // Second widget (w=4) fits next to it at (8,0)
        $this->assertEquals(8, $result['packed'][1]['x']);
        $this->assertEquals(0, $result['packed'][1]['y']);
        $this->assertEquals(4, $result['packed'][1]['w']);
    }

    public function test_existing_tiles_respected(): void
    {
        $existing = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'global', 'config' => []],
        ];

        $newWidgets = [
            ['key' => 'billing.refund_ratio', 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutPacker::pack($existing, $newWidgets);

        $this->assertCount(2, $result['packed']);
        $this->assertEmpty($result['pending']);

        // New widget should not overlap with existing (0,0)→(8,4)
        $packed = $result['packed'][1];
        $this->assertTrue(
            $packed['x'] >= 8 || $packed['y'] >= 4,
            "New widget at ({$packed['x']},{$packed['y']}) overlaps existing tile",
        );
    }

    public function test_max_tiles_creates_pending(): void
    {
        // Fill to MAX_TILES with existing tiles
        $existing = [];
        for ($i = 0; $i < 30; $i++) {
            $existing[] = ['key' => "existing.{$i}", 'x' => 0, 'y' => $i * 2, 'w' => 4, 'h' => 2, 'scope' => 'global', 'config' => []];
        }

        $newWidgets = [
            ['key' => 'billing.revenue_trend', 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutPacker::pack($existing, $newWidgets);

        $this->assertCount(30, $result['packed']);
        $this->assertCount(1, $result['pending']);
        $this->assertEquals('billing.revenue_trend', $result['pending'][0]);
    }
}
