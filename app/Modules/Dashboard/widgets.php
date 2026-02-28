<?php

// Dashboard module widget declarations (ADR-149, ADR-156)
return [
    // Existing (ADR-149)
    \App\Modules\Dashboard\Widgets\BillingRevenueTrendWidget::class,
    \App\Modules\Dashboard\Widgets\BillingRefundRatioWidget::class,
    \App\Modules\Dashboard\Widgets\BillingArOutstandingWidget::class,

    // Activity (ADR-156)
    \App\Modules\Dashboard\Widgets\BillingLastPaymentsWidget::class,
    \App\Modules\Dashboard\Widgets\BillingLastInvoicesWidget::class,
    \App\Modules\Dashboard\Widgets\BillingLastRefundsWidget::class,

    // KPIs (ADR-156)
    \App\Modules\Dashboard\Widgets\BillingRevenueMtdWidget::class,
    \App\Modules\Dashboard\Widgets\BillingMrrWidget::class,

    // Risk (ADR-156)
    \App\Modules\Dashboard\Widgets\BillingFailedPayments7dWidget::class,
    \App\Modules\Dashboard\Widgets\BillingPendingDunningWidget::class,
    \App\Modules\Dashboard\Widgets\BillingTopFailureReasonsWidget::class,

    // Timeseries (ADR-156)
    \App\Modules\Dashboard\Widgets\BillingCashflowTrendWidget::class,
];
