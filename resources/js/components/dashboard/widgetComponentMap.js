import { defineAsyncComponent, shallowRef } from 'vue'

/**
 * Widget component registry — maps component keys (from WidgetManifest::component())
 * to Vue components. Used by both platform and company dashboards.
 */
const map = {
  // Existing (ADR-149)
  BillingRevenueTrend: defineAsyncComponent(() => import('@/pages/platform/billing/_RevenueTrendWidget.vue')),
  BillingRefundRatio: defineAsyncComponent(() => import('@/pages/platform/billing/_RefundRatioWidget.vue')),
  BillingArOutstanding: defineAsyncComponent(() => import('@/pages/platform/billing/_ArOutstandingWidget.vue')),

  // Activity (ADR-156)
  BillingLastPayments: defineAsyncComponent(() => import('@/pages/platform/billing/_LastPaymentsWidget.vue')),
  BillingLastInvoices: defineAsyncComponent(() => import('@/pages/platform/billing/_LastInvoicesWidget.vue')),
  BillingLastRefunds: defineAsyncComponent(() => import('@/pages/platform/billing/_LastRefundsWidget.vue')),

  // KPIs (ADR-156)
  BillingRevenueMtd: defineAsyncComponent(() => import('@/pages/platform/billing/_RevenueMtdWidget.vue')),
  BillingMrr: defineAsyncComponent(() => import('@/pages/platform/billing/_MrrWidget.vue')),

  // Risk (ADR-156)
  BillingFailedPayments7d: defineAsyncComponent(() => import('@/pages/platform/billing/_FailedPayments7dWidget.vue')),
  BillingPendingDunning: defineAsyncComponent(() => import('@/pages/platform/billing/_PendingDunningWidget.vue')),
  BillingTopFailureReasons: defineAsyncComponent(() => import('@/pages/platform/billing/_TopFailureReasonsWidget.vue')),

  // Timeseries (ADR-156)
  BillingCashflowTrend: defineAsyncComponent(() => import('@/pages/platform/billing/_CashflowTrendWidget.vue')),

  // Compliance (ADR-196)
  ComplianceRate: defineAsyncComponent(() => import('@/views/company-dashboard/_ComplianceRateWidget.vue')),
  CompliancePending: defineAsyncComponent(() => import('@/views/company-dashboard/_PendingWidget.vue')),
  ComplianceOverdue: defineAsyncComponent(() => import('@/views/company-dashboard/_OverdueWidget.vue')),
  ComplianceRoles: defineAsyncComponent(() => import('@/views/company-dashboard/_RolesWidget.vue')),
  ComplianceTypes: defineAsyncComponent(() => import('@/views/company-dashboard/_TypesWidget.vue')),

  // Workforce / Planning — P1 (ADR-744)
  WorkforceClockLive: defineAsyncComponent(() => import('@/views/company-dashboard/_ClockLiveWidget.vue')),
  WorkforceHeadcount: defineAsyncComponent(() => import('@/views/company-dashboard/_HeadcountWidget.vue')),
  WorkforceAnomalies: defineAsyncComponent(() => import('@/views/company-dashboard/_AnomaliesWidget.vue')),
  WorkforceTimesheetPending: defineAsyncComponent(() => import('@/views/company-dashboard/_TimesheetPendingWidget.vue')),
  WorkforceLeavePending: defineAsyncComponent(() => import('@/views/company-dashboard/_LeavePendingWidget.vue')),
  WorkforceHoursTrend: defineAsyncComponent(() => import('@/views/company-dashboard/_HoursTrendWidget.vue')),
  PlanningShifts: defineAsyncComponent(() => import('@/views/company-dashboard/_PlanningShiftsWidget.vue')),

  // Plan badge (ADR-372: pipeline-driven). Onboarding is outside grid (ADR-383).
  PlanBadge: defineAsyncComponent(() => import('@/pages/company/dashboard/_PlanBadgeWidget.vue')),

  // Operations — panneaux composites éditoriaux (ADR-745)
  ShipmentsOverview: defineAsyncComponent(() => import('@/views/company-dashboard/_ShipmentsOverviewWidget.vue')),
  LogisticsFleet: defineAsyncComponent(() => import('@/views/company-dashboard/_LogisticsFleetWidget.vue')),
  DocumentsDetail: defineAsyncComponent(() => import('@/views/company-dashboard/_DocumentsDetailWidget.vue')),

  // Operations — shipment KPIs (ADR-374)
  ShipmentsToday: defineAsyncComponent(() => import('@/views/company-dashboard/_ShipmentsTodayWidget.vue')),
  ShipmentsInTransit: defineAsyncComponent(() => import('@/views/company-dashboard/_ShipmentsInTransitWidget.vue')),
  ShipmentsLate: defineAsyncComponent(() => import('@/views/company-dashboard/_ShipmentsLateWidget.vue')),
  ShipmentsUnassigned: defineAsyncComponent(() => import('@/views/company-dashboard/_ShipmentsUnassignedWidget.vue')),
  DriversActive: defineAsyncComponent(() => import('@/views/company-dashboard/_DriversActiveWidget.vue')),

  // Operations — delivery KPIs (ADR-374)
  DeliveriesMyToday: defineAsyncComponent(() => import('@/views/company-dashboard/_DeliveriesMyTodayWidget.vue')),
  DeliveriesNext: defineAsyncComponent(() => import('@/views/company-dashboard/_DeliveriesNextWidget.vue')),
  DeliveriesCompletedToday: defineAsyncComponent(() => import('@/views/company-dashboard/_DeliveriesCompletedTodayWidget.vue')),

  // AI — platform KPIs (ADR-413)
  AiDocsAnalyzed: defineAsyncComponent(() => import('@/pages/platform/ai/_AiDocsAnalyzedWidget.vue')),
  AiDetections: defineAsyncComponent(() => import('@/pages/platform/ai/_AiDetectionsWidget.vue')),
  AiAutoActions: defineAsyncComponent(() => import('@/pages/platform/ai/_AiAutoActionsWidget.vue')),
  AiConfidence: defineAsyncComponent(() => import('@/pages/platform/ai/_AiConfidenceWidget.vue')),

  // Platform — panneaux composites éditoriaux (ADR-746, P2)
  PlatformCommercialOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/_PlatformCommercialWidget.vue')),
  PlatformPlanDistribution: defineAsyncComponent(() => import('@/views/platform-dashboard/_PlatformPlanDistributionWidget.vue')),
  PlatformCompaniesOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/_PlatformCompaniesWidget.vue')),
  PlatformSystemHealth: defineAsyncComponent(() => import('@/views/platform-dashboard/_PlatformSystemHealthWidget.vue')),
  PlatformSupportOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/_PlatformSupportWidget.vue')),
  PlatformSecurityOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/_PlatformSecurityWidget.vue')),
  PlatformCatalogOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/_PlatformCatalogWidget.vue')),
}

export function resolveWidgetComponent(componentKey) {
  return map[componentKey] || null
}

export function registerWidgetComponent(componentKey, component) {
  map[componentKey] = component
}

export default map
