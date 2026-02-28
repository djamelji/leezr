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
}

export function resolveWidgetComponent(componentKey) {
  return map[componentKey] || null
}

export function registerWidgetComponent(componentKey, component) {
  map[componentKey] = component
}

export default map
