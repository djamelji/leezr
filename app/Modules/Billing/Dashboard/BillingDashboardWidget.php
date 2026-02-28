<?php

namespace App\Modules\Billing\Dashboard;

interface BillingDashboardWidget
{
    public function key(): string;

    public function labelKey(): string;

    public function defaultPeriod(): string;

    public function resolve(int $companyId, string $period): array;
}
