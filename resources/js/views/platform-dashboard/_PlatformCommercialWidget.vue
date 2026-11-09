<script setup>
// ADR-746 (P2) — hero « Pilotage commercial ». MRR/ARR + abonnements + évolution MRR
// (12 mois, LzrApexChart). Données réelles (BillingMetricsCalculationService). Montants en cents.
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const isCompact = computed(() => props.viewport?.density === 'S')

const money = cents => ((Number(cents) || 0) / 100).toLocaleString('fr-FR', { style: 'currency', currency: d.value.currency || 'EUR', maximumFractionDigits: 0 })
const pct = v => `${Math.round((Number(v) || 0) * 100)}%`

const mrr = computed(() => d.value.mrr ?? 0)
const mrrPrev = computed(() => d.value.mrr_previous ?? 0)
const delta = computed(() => mrrPrev.value > 0 ? (mrr.value - mrrPrev.value) / mrrPrev.value : 0)
const histLabels = computed(() => d.value.mrr_history?.labels ?? [])
const histSeries = computed(() => (d.value.mrr_history?.series ?? []).map(v => Math.round((Number(v) || 0) / 100)))
const hasHistory = computed(() => histSeries.value.some(v => v > 0))
const series = computed(() => [{ name: 'MRR', data: histSeries.value }])
</script>

<template>
  <div v-if="isCompact" class="widget-root d-flex flex-column pa-4">
    <div class="d-flex align-center justify-space-between gap-2">
      <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.commercialOverview') }}</span>
      <VAvatar color="primary" variant="tonal" size="34" rounded><VIcon icon="tabler-chart-line" size="18" /></VAvatar>
    </div>
    <div class="text-h4 font-weight-medium text-high-emphasis mt-1">{{ money(mrr) }}</div>
    <div class="text-caption text-medium-emphasis">MRR · {{ money(d.arr) }} ARR</div>
  </div>

  <div v-else class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.commercialOverview') }}</div>
        <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.commercialSub') }}</div>
      </div>
      <VAvatar color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-chart-line" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="image" class="bg-transparent flex-grow-1" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />

    <div v-else class="flex-grow-1 d-flex gap-4">
      <div class="comm-aside d-flex flex-column justify-center gap-3 flex-shrink-0">
        <div>
          <div class="d-flex align-baseline gap-2">
            <span class="text-h4 font-weight-medium text-high-emphasis">{{ money(mrr) }}</span>
            <span v-if="delta !== 0" class="text-caption font-weight-medium" :class="delta >= 0 ? 'text-success' : 'text-error'">
              {{ delta >= 0 ? '▲' : '▼' }} {{ pct(Math.abs(delta)) }}
            </span>
          </div>
          <div class="text-caption text-medium-emphasis">MRR · {{ money(d.arr) }} ARR</div>
        </div>
        <VDivider />
        <div class="d-flex flex-column gap-2">
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-repeat" color="primary" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.activeSubs', { n: d.active_subscriptions ?? 0 }) }}</span>
          </div>
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-hourglass" color="info" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.trials', { n: d.trialing_subscriptions ?? 0 }) }}</span>
          </div>
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-arrow-bounce" :color="(d.churn_rate ?? 0) > 0 ? 'warning' : 'disabled'" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.churn') }} {{ pct(d.churn_rate) }} · {{ t('platform.widgets.conversion') }} {{ pct(d.trial_conversion_rate) }}</span>
          </div>
        </div>
      </div>

      <div class="comm-chart flex-grow-1 d-flex align-center">
        <LzrApexChart
          v-if="hasHistory"
          class="w-100" type="area"
          :series="series" :categories="histLabels"
          :height="viewport?.density === 'M' ? 170 : 220"
          :format-y="v => money(v * 100)"
        />
        <div v-else class="w-100 text-center">
          <VIcon icon="tabler-chart-line" size="28" class="text-disabled mb-2" />
          <div class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.noHistory') }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; }
.comm-aside { inline-size: 190px; }
@container dashboard-tile (max-width: 560px) {
  .widget-root .d-flex.gap-4 { flex-direction: column; }
  .comm-aside { inline-size: 100%; }
}
</style>
