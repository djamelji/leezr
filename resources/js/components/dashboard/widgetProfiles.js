/**
 * Widget Intelligence Layer (WIL) — UI Profile Registry (ADR-201).
 *
 * Maps every dashboard widget key to a UI profile that determines
 * how the Presentation Engine computes breakpoints.
 *
 * Profiles: 'kpi' | 'kpi-rich' | 'list' | 'chart'
 * Unknown keys → 'unknown' (density-only, no WIL).
 *
 * Uniform Platform + Company — no surface-specific branches.
 */

const WIDGET_PROFILES = {
  // Platform billing — KPI
  'billing.revenue_mtd': 'kpi',
  'billing.mrr': 'kpi',
  'billing.ar_outstanding': 'kpi',
  'billing.failed_payments_7d': 'kpi',
  'billing.pending_dunning': 'kpi',

  // Platform billing — KPI-rich
  'billing.refund_ratio': 'kpi-rich',

  // Platform billing — List
  'billing.last_payments': 'list',
  'billing.last_invoices': 'list',
  'billing.last_refunds': 'list',
  'billing.top_failure_reasons': 'list',

  // Platform billing — Chart
  'billing.revenue_trend': 'chart',
  'billing.cashflow_trend_30d': 'chart',

  // Company compliance — KPI
  ComplianceRate: 'kpi',
  CompliancePending: 'kpi',
  ComplianceOverdue: 'kpi',

  // Company compliance — List
  ComplianceRoles: 'list',
  ComplianceTypes: 'list',
}

/**
 * @param {string} key — widget key (e.g. 'billing.revenue_mtd', 'ComplianceRate')
 * @returns {'kpi'|'kpi-rich'|'list'|'chart'|'unknown'}
 */
export function getWidgetProfile(key) {
  const profile = WIDGET_PROFILES[key]

  if (!profile && import.meta.env.DEV) {
    console.warn(`[WIL] Unknown widget profile for key "${key}" — add it to widgetProfiles.js`)
  }

  return profile || 'unknown'
}
