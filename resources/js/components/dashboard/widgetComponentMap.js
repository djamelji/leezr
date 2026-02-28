import { defineAsyncComponent, shallowRef } from 'vue'

/**
 * Widget component registry — maps component keys (from WidgetManifest::component())
 * to Vue components. Used by both platform and company dashboards.
 */
const map = {
  BillingRevenueTrend: defineAsyncComponent(() => import('@/pages/platform/billing/_RevenueTrendWidget.vue')),
  BillingRefundRatio: defineAsyncComponent(() => import('@/pages/platform/billing/_RefundRatioWidget.vue')),
  BillingArOutstanding: defineAsyncComponent(() => import('@/pages/platform/billing/_ArOutstandingWidget.vue')),
}

export function resolveWidgetComponent(componentKey) {
  return map[componentKey] || null
}

export function registerWidgetComponent(componentKey, component) {
  map[componentKey] = component
}

export default map
