<?php

namespace App\Modules\Billing\Dashboard\Widgets;

use App\Core\Billing\ReadModels\PlatformBillingWidgetsReadService;
use App\Modules\Billing\Dashboard\BillingDashboardWidget;

class ArOutstandingWidget implements BillingDashboardWidget
{
    public function key(): string
    {
        return 'ar_outstanding';
    }

    public function labelKey(): string
    {
        return 'platformBilling.widgets.arOutstanding';
    }

    public function defaultPeriod(): string
    {
        return '30d';
    }

    public function resolve(int $companyId, string $period): array
    {
        $currency = PlatformBillingWidgetsReadService::currencyForCompany($companyId);
        $outstanding = PlatformBillingWidgetsReadService::arOutstanding($companyId)['outstanding'];

        return [
            'key' => $this->key(),
            'currency' => $currency,
            'outstanding' => $outstanding,
        ];
    }
}
