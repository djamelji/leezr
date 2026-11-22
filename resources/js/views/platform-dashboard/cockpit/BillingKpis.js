// ADR-856 (Leezr 5.0 L4) — Platform billing / AI KPI widgets as specs over KpiFromData. Each spec reproduces the
// former widget's data rule exactly (cents, MULTI currency, not_supported) — no formula changed.
import { defineComponent, h } from 'vue'
import BillingTrend from './BillingTrend.vue'
import KpiFromData from './KpiFromData.vue'

const money = d => (d.currency === 'MULTI' ? null : d)

function kpi(name, specFn) {
  return defineComponent({
    name,
    props: { data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'global' }, viewport: { type: Object, default: () => ({}) } },
    setup(props) {
      const { t } = useI18n()

      return () => h(KpiFromData, { data: props.data, loading: props.loading, spec: specFn(t) })
    },
  })
}

export const BillingMrrKpi = kpi('BillingMrrKpi', t => ({
  label: t('platformBilling.widgets.mrr'), icon: 'tabler-repeat', color: 'primary', format: 'money',
  value: d => (d.meta?.not_supported || d.data === null || d.data === undefined ? null : Number(d.data)),
  currency: d => d.currency || 'EUR',
  emptyHint: t('l4.kpi.mrrUnsupported'),
  to: { name: 'platform-billing-tab', params: { tab: 'subscriptions' } }, linkLabel: t('l4.kpi.seeSubscriptions'),
}))

export const BillingArOutstandingKpi = kpi('BillingArOutstandingKpi', t => ({
  label: t('platformBilling.widgets.arOutstanding'), icon: 'tabler-file-dollar', format: 'money',
  color: d => ((d.outstanding ?? 0) > 0 ? 'warning' : 'success'),
  value: d => (money(d) === null || d.outstanding === undefined ? null : Number(d.outstanding)),
  currency: d => d.currency || 'EUR',
  context: t('l4.kpi.arContext'),
  emptyHint: t('l4.kpi.multiCurrency'),
  to: { name: 'platform-billing-tab', params: { tab: 'invoices' } }, linkLabel: t('l4.kpi.seeInvoices'),
}))

export const BillingPendingDunningKpi = kpi('BillingPendingDunningKpi', t => ({
  label: t('platformBilling.widgets.pendingDunning'), icon: 'tabler-mail-forward',
  color: d => ((d.count ?? 0) > 0 ? 'warning' : 'success'),
  value: d => (d.count === undefined ? null : d.count),
  context: d => ((d.count ?? 0) > 0 ? t('l4.kpi.dunningSome') : t('l4.kpi.dunningNone')),
  to: { name: 'platform-billing-tab', params: { tab: 'dunning' } }, linkLabel: t('l4.kpi.seeDunning'),
}))

export const BillingRefundRatioKpi = kpi('BillingRefundRatioKpi', t => ({
  label: t('platformBilling.widgets.refundRatio'), icon: 'tabler-receipt-refund', format: 'pct', decimals: 1,
  color: d => ((d.ratio ?? 0) > 5 ? 'error' : (d.ratio ?? 0) > 0 ? 'warning' : 'success'),
  value: d => (typeof d.ratio === 'number' ? d.ratio : null),
  context: t('l4.kpi.refundContext'),
  breakdown: d => [
    { label: t('l4.kpi.revenue'), value: new Intl.NumberFormat('fr-FR', { style: 'currency', currency: d.currency && d.currency !== 'MULTI' ? d.currency : 'EUR', maximumFractionDigits: 0 }).format((d.revenue ?? 0) / 100) },
    { label: t('l4.kpi.refunds'), value: new Intl.NumberFormat('fr-FR', { style: 'currency', currency: d.currency && d.currency !== 'MULTI' ? d.currency : 'EUR', maximumFractionDigits: 0 }).format((d.refunds ?? 0) / 100), tone: (d.refunds ?? 0) > 0 ? 'warning' : '' },
  ],
  to: { name: 'platform-billing-tab', params: { tab: 'credit-notes' } }, linkLabel: t('l4.kpi.seeCreditNotes'),
}))

const aiTo = { name: 'platform-operations-tab', params: { tab: 'ai' } }

export const AiDocsAnalyzedKpi = kpi('AiDocsAnalyzedKpi', t => ({
  label: t('platformAi.widgets.docsAnalyzed'), icon: 'tabler-file-search', color: 'primary',
  value: d => d.data?.count ?? null,
  context: d => t('platformAi.widgets.extractionRate', { rate: d.data?.extraction_rate ?? 0 }),
  to: aiTo, linkLabel: t('l4.kpi.seeAi'),
}))

export const AiDetectionsKpi = kpi('AiDetectionsKpi', t => ({
  label: t('platformAi.widgets.detections'), icon: 'tabler-radar',
  color: d => ((d.data?.expirations ?? 0) + (d.data?.mismatches ?? 0) > 0 ? 'warning' : 'success'),
  value: d => (d.data ? (d.data.expirations ?? 0) + (d.data.mismatches ?? 0) : null),
  breakdown: d => [{ label: t('l4.kpi.expirations'), value: d.data?.expirations ?? 0 }, { label: t('l4.kpi.mismatches'), value: d.data?.mismatches ?? 0 }],
  to: aiTo, linkLabel: t('l4.kpi.seeAi'),
}))

export const AiAutoActionsKpi = kpi('AiAutoActionsKpi', t => ({
  label: t('platformAi.widgets.autoActions'), icon: 'tabler-robot', color: 'success',
  value: d => d.data?.total ?? null,
  breakdown: d => [{ label: t('l4.kpi.autoFills'), value: d.data?.auto_fills ?? 0, tone: 'success' }, { label: t('l4.kpi.autoRejects'), value: d.data?.auto_rejects ?? 0, tone: (d.data?.auto_rejects ?? 0) ? 'warning' : '' }],
  to: aiTo, linkLabel: t('l4.kpi.seeAi'),
}))

export const AiConfidenceKpi = kpi('AiConfidenceKpi', t => ({
  label: t('platformAi.widgets.confidence'), icon: 'tabler-gauge', color: 'info', format: 'pct', decimals: 0,
  value: d => (typeof d.data?.extraction_rate === 'number' ? d.data.extraction_rate : null),
  context: d => t('platformAi.widgets.confidenceDetail', { lowCount: d.data?.low_confidence ?? 0 }),
  to: aiTo, linkLabel: t('l4.kpi.seeAi'),
}))

// ── Trends (chart family) ─────────────────────────────────────────────

function trend(name, specFn) {
  return defineComponent({
    name,
    props: { data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'global' }, viewport: { type: Object, default: () => ({}) } },
    setup(props) {
      const { t } = useI18n()

      return () => h(BillingTrend, { data: props.data, loading: props.loading, ...specFn(t) })
    },
  })
}

export const BillingRevenueTrendChart = trend('BillingRevenueTrendChart', t => ({
  title: t('platformBilling.widgets.revenueTrend'), icon: 'tabler-chart-line', color: 'primary',
  to: { name: 'platform-billing-tab', params: { tab: 'invoices' } }, linkLabel: t('l4.kpi.seeInvoices'),
}))
export const BillingCashflowTrendChart = trend('BillingCashflowTrendChart', t => ({
  title: t('platformBilling.widgets.cashflowTrend'), icon: 'tabler-cash', color: 'success',
  to: { name: 'platform-billing-tab', params: { tab: 'payments' } }, linkLabel: t('l4.kpi.seePayments'),
}))
