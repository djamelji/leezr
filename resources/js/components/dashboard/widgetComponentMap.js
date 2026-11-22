import { defineAsyncComponent } from 'vue'

/**
 * Widget component registry — maps component keys (from WidgetManifest::component())
 * to Vue components. Used by both platform and company dashboards.
 */
const map = {
  // Existing (ADR-149)
  BillingRevenueTrend: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.BillingRevenueTrendChart)), // ADR-856 chart family
  BillingRefundRatio: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.BillingRefundRatioKpi)), // ADR-856 KPI family
  BillingArOutstanding: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.BillingArOutstandingKpi)), // ADR-856 KPI family

  // Activity (ADR-156)
  BillingLastPayments: defineAsyncComponent(() => import('@/pages/platform/billing/_LastPaymentsWidget.vue')),
  BillingLastInvoices: defineAsyncComponent(() => import('@/pages/platform/billing/_LastInvoicesWidget.vue')),
  BillingLastRefunds: defineAsyncComponent(() => import('@/pages/platform/billing/_LastRefundsWidget.vue')),

  // KPIs (ADR-156)
  // ADR-855 — Leezr 5.0 cockpit presentation (same server widget, same data, size-aware KPI)
  BillingRevenueMtd: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitCollected.vue')),
  BillingMrr: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.BillingMrrKpi)), // ADR-856 KPI family

  // Risk (ADR-156)
  BillingFailedPayments7d: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitFailedPayments.vue')),
  BillingPendingDunning: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.BillingPendingDunningKpi)), // ADR-856 KPI family
  BillingTopFailureReasons: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/FailureReasons.vue')), // ADR-856 list family

  // Timeseries (ADR-156)
  BillingCashflowTrend: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.BillingCashflowTrendChart)), // ADR-856 chart family

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
  AiDocsAnalyzed: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.AiDocsAnalyzedKpi)), // ADR-856 KPI family
  AiDetections: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.AiDetectionsKpi)), // ADR-856 KPI family
  AiAutoActions: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.AiAutoActionsKpi)), // ADR-856 KPI family
  AiConfidence: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/BillingKpis').then(m => m.AiConfidenceKpi)), // ADR-856 KPI family

  // Platform — cockpit Leezr 5.0 (ADR-855) : mêmes widgets serveur, présentation V35 adaptée à la taille réelle.
  // Les anciens composants (ADR-746) restent dans views/platform-dashboard/ jusqu'à la fin de L4.
  PlatformCommercialOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitRevenue.vue')),
  PlatformPlanDistribution: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitPlans.vue')),
  PlatformCompaniesOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitCompanies.vue')),
  PlatformSystemHealth: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitHealth.vue')),
  PlatformSupportOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitSupport.vue')),
  PlatformSecurityOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitSecurity.vue')),
  PlatformCatalogOverview: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitModules.vue')),
  PlatformSignups: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitSignups.vue')),
  PlatformTrialsEnding: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitTrials.vue')),
  PlatformRecentActivity: defineAsyncComponent(() => import('@/views/platform-dashboard/cockpit/CockpitActivity.vue')),
}

export function resolveWidgetComponent(componentKey) {
  return map[componentKey] || null
}

export function registerWidgetComponent(componentKey, component) {
  map[componentKey] = component
}

export default map
