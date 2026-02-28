<?php

namespace Tests\Unit;

use App\Core\Billing\PaymentRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Dashboard\DashboardWidgetRegistry;
use App\Modules\Dashboard\LayoutValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-149 D4e.3: LayoutValidator unit tests.
 */
class LayoutValidatorTest extends TestCase
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

    public function test_valid_layout_passes(): void
    {
        $tiles = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'global', 'config' => []],
            ['key' => 'billing.refund_ratio', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutValidator::validate($tiles);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_negative_x_fails(): void
    {
        $tiles = [
            ['key' => 'billing.revenue_trend', 'x' => -1, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutValidator::validate($tiles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('x must be >= 0', $result['errors'][0]);
    }

    public function test_negative_y_fails(): void
    {
        $tiles = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => -2, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutValidator::validate($tiles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('y must be >= 0', $result['errors'][0]);
    }

    public function test_overflow_right_fails(): void
    {
        $tiles = [
            ['key' => 'billing.revenue_trend', 'x' => 10, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutValidator::validate($tiles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds 12 columns', $result['errors'][0]);
    }

    public function test_overlap_detected(): void
    {
        $tiles = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'scope' => 'global', 'config' => []],
            ['key' => 'billing.refund_ratio', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 4, 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutValidator::validate($tiles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('overlap', $result['errors'][0]);
    }

    public function test_min_max_enforced(): void
    {
        // BillingRevenueTrend has min_w=3, max_w=12 — w=2 should fail
        $tiles = [
            ['key' => 'billing.revenue_trend', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 4, 'scope' => 'global', 'config' => []],
        ];

        $result = LayoutValidator::validate($tiles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('outside', $result['errors'][0]);
    }

    public function test_max_tiles_enforced(): void
    {
        $tiles = [];
        for ($i = 0; $i < 31; $i++) {
            $tiles[] = ['key' => "fake.widget.{$i}", 'x' => 0, 'y' => $i * 2, 'w' => 4, 'h' => 2, 'scope' => 'global', 'config' => []];
        }

        $result = LayoutValidator::validate($tiles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Maximum 30', $result['errors'][0]);
    }
}
