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

    // Compliance — company-side, client-resolved (ADR-327)
    \App\Modules\Dashboard\Widgets\ComplianceRateWidget::class,
    \App\Modules\Dashboard\Widgets\CompliancePendingWidget::class,
    \App\Modules\Dashboard\Widgets\ComplianceOverdueWidget::class,
    \App\Modules\Dashboard\Widgets\ComplianceRolesWidget::class,
    \App\Modules\Dashboard\Widgets\ComplianceTypesWidget::class,

    // Onboarding + Plan (ADR-372: pipeline-driven, client-resolved)
    \App\Modules\Dashboard\Widgets\OnboardingSetupWidget::class,
    \App\Modules\Dashboard\Widgets\PlanBadgeWidget::class,

    // Operations — shipment KPIs, server-resolved (ADR-374)
    \App\Modules\Dashboard\Widgets\ShipmentsTodayWidget::class,
    \App\Modules\Dashboard\Widgets\ShipmentsInTransitWidget::class,
    \App\Modules\Dashboard\Widgets\ShipmentsLateWidget::class,
    \App\Modules\Dashboard\Widgets\ShipmentsUnassignedWidget::class,
    \App\Modules\Dashboard\Widgets\DriversActiveWidget::class,

    // Operations — delivery KPIs, server-resolved (ADR-374)
    \App\Modules\Dashboard\Widgets\DeliveriesMyTodayWidget::class,
    \App\Modules\Dashboard\Widgets\DeliveriesNextWidget::class,
    \App\Modules\Dashboard\Widgets\DeliveriesCompletedTodayWidget::class,
];
