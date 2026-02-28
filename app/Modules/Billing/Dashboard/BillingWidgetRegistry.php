<?php

namespace App\Modules\Billing\Dashboard;

use App\Modules\Billing\Dashboard\Widgets\ArOutstandingWidget;
use App\Modules\Billing\Dashboard\Widgets\RefundRatioWidget;
use App\Modules\Billing\Dashboard\Widgets\RevenueTrendWidget;

final class BillingWidgetRegistry
{
    /** @return array<BillingDashboardWidget> */
    public static function all(): array
    {
        return [
            app(RevenueTrendWidget::class),
            app(RefundRatioWidget::class),
            app(ArOutstandingWidget::class),
        ];
    }

    public static function find(string $key): ?BillingDashboardWidget
    {
        foreach (self::all() as $widget) {
            if ($widget->key() === $key) {
                return $widget;
            }
        }

        return null;
    }
}
