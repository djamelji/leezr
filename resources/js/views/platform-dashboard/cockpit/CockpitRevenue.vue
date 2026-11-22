<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Revenus » for widget platform.commercial_overview (view_billing).
// Sources (unchanged) : the widget's KPIs (recurringPortfolio, ADR-841) + /billing/commercial-series (ADR-747).
// Size-aware, content recomposed per real size :
//   KPI size (≤ 3×2)  → MRR, change vs previous month, sparkline
//   standard / wide   → KPI strip (MRR, ARR, subscriptions, trials) + MRR chart (period, N-1 comparison)
//   expanded          → + aside : churn, trial conversion, MRR change
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import AppSegmented from '@/components/app/AppSegmented.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { $platformApi } from '@/utils/platformApi'
import { money, pctChange } from './cockpitFormat'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()

const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const kpiSize = computed(() => rootW.value > 0 && (rootH.value < 210 || rootW.value < 300))

const d = computed(() => props.data || {})
const cur = computed(() => d.value.currency || 'EUR')
const delta = computed(() => pctChange(d.value.mrr, d.value.mrr_previous))

const period = ref('month')
const compare = ref(true)
const series = ref(null)
const fetchState = ref('idle')

async function fetchSeries() {
  fetchState.value = 'loading'
  try {
    series.value = await $platformApi(`/billing/commercial-series?period=${period.value}&compare=${compare.value ? 1 : 0}`)
    fetchState.value = 'ready'
  }
  catch {
    fetchState.value = 'error'
  }
}
onMounted(fetchSeries)
watch([period, compare], fetchSeries)

const spark = computed(() => (series.value?.current || []).map(v => Math.round((Number(v) || 0) / 100)))
const hasHistory = computed(() => (series.value?.current || []).some(v => Number(v) > 0))

const shellState = computed(() => {
  if (props.loading && !props.data) return 'loading'
  if (d.value.error) return 'error'
  if (fetchState.value === 'error') return 'error'
  if (fetchState.value === 'loading' && !series.value) return 'loading'
  if (!hasHistory.value && !d.value.mrr) return 'empty'

  return 'ready'
})

const chartSeries = computed(() => {
  const s = [{ name: t('cockpit.revenue.mrr'), data: series.value?.current || [] }]
  if (compare.value && series.value?.previous) s.push({ name: t('cockpit.revenue.previousYear'), data: series.value.previous })

  return s
})
// Chart height follows the available height, BUCKETED (40 px) so it changes only when the tile is really resized
// (a height recomputed on every measurement makes ApexCharts fail — see 04-dashboards-widgets §2).
const chartH = h => Math.max(160, Math.min(440, Math.floor((h - 175) / 30) * 30))
const periods = computed(() => [{ value: 'week', label: t('cockpit.period.week') }, { value: 'month', label: t('cockpit.period.month') }, { value: 'year', label: t('cockpit.period.year') }])
const billingTo = { name: 'platform-billing-tab', params: { tab: 'overview' } }
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <AppKpiTile
      v-if="kpiSize"
      bare
      accent
      :label="t('cockpit.revenue.mrrShort')"
      icon="tabler-chart-arrows-vertical"
      :value="d.error ? null : (d.mrr ?? null)"
      format="money"
      :currency="cur"
      :delta="delta"
      :delta-label="t('cockpit.revenue.vsPrevious')"
      :empty-hint="t('cockpit.unavailable')"
      :spark="spark"
      :to="billingTo"
    />
    <AppWidgetShell
      v-else
      v-model:period="period"
      bare
      :title="t('cockpit.revenue.title')"
      :subtitle="series?.label ? `${t('cockpit.revenue.subtitle')} · ${series.label}` : t('cockpit.revenue.subtitle')"
      icon="tabler-report-money"
      :periods="periods"
      :link="t('cockpit.revenue.link')"
      :to="billingTo"
      :state="shellState"
      empty-icon="tabler-coins"
      :empty-title="t('cockpit.revenue.emptyTitle')"
      :empty-text="t('cockpit.revenue.emptyText')"
      @retry="fetchSeries"
    >
      <template #default="{ mode, h }">
        <div
          class="cr-kpis"
          :class="{ 'cr-kpis--tight': mode === 'compact' }"
        >
          <div class="cr-kpi cr-kpi--main">
            <span class="cr-kpi__label">{{ t('cockpit.revenue.mrr') }}</span>
            <span
              class="cr-kpi__value"
              data-test="cockpit-mrr"
            >{{ money(d.mrr, cur) }}</span>
            <span
              v-if="delta !== null"
              class="cr-kpi__delta"
              :class="delta >= 0 ? 'text-success' : 'text-error'"
            >
              <VIcon
                size="14"
                :icon="delta >= 0 ? 'tabler-arrow-up-right' : 'tabler-arrow-down-right'"
              />{{ delta >= 0 ? '+' : '' }}{{ delta }} % <span class="text-medium-emphasis font-weight-regular">{{ t('cockpit.revenue.vsPrevious') }}</span>
            </span>
          </div>
          <div class="cr-kpi">
            <span class="cr-kpi__label">{{ t('cockpit.revenue.arr') }}</span><span class="cr-kpi__value cr-kpi__value--sm">{{ money(d.arr, cur) }}</span>
          </div>
          <div
            v-if="mode !== 'compact'"
            class="cr-kpi"
          >
            <span class="cr-kpi__label">{{ t('cockpit.revenue.active') }}</span><span
              class="cr-kpi__value cr-kpi__value--sm"
              data-test="cockpit-active-subscriptions"
            >{{ d.active_subscriptions ?? '—' }}</span>
          </div>
          <div
            v-if="mode !== 'compact'"
            class="cr-kpi"
          >
            <span class="cr-kpi__label">{{ t('cockpit.revenue.trials') }}</span><span class="cr-kpi__value cr-kpi__value--sm">{{ d.trialing_subscriptions ?? '—' }}</span>
          </div>
          <VSpacer />
          <button
            v-if="mode !== 'compact'"
            type="button"
            class="cr-compare"
            :class="{ 'cr-compare--on': compare }"
            :aria-pressed="compare"
            @click="compare = !compare"
          >
            <span class="cr-compare__swatch" />{{ t('cockpit.revenue.compare') }}
          </button>
        </div>

        <div class="d-flex gap-5 flex-grow-1 min-w-0">
          <div class="flex-grow-1 min-w-0 cr-chart">
            <LzrApexChart
              type="area"
              :series="chartSeries"
              :categories="series?.labels || []"
              :height="chartH(h)"
              :compare="compare && !!series?.previous"
              :format-y="v => money(v, cur)"
            />
          </div>
          <div
            v-if="mode === 'expanded'"
            class="cr-aside"
          >
            <div class="text-overline text-medium-emphasis mb-1">
              {{ t('cockpit.revenue.health') }}
            </div>
            <div class="cr-aside__row">
              <span>{{ t('cockpit.revenue.mrrChange') }}</span>
              <strong :class="(d.mrr - d.mrr_previous) >= 0 ? 'text-success' : 'text-error'">{{ (d.mrr - d.mrr_previous) >= 0 ? '+' : '' }}{{ money((d.mrr || 0) - (d.mrr_previous || 0), cur) }}</strong>
            </div>
            <div class="cr-aside__row">
              <span>{{ t('cockpit.revenue.churn') }}</span><strong>{{ d.churn_rate === null || d.churn_rate === undefined ? '—' : `${Math.round(d.churn_rate * 1000) / 10} %` }}</strong>
            </div>
            <div class="cr-aside__row">
              <span>{{ t('cockpit.revenue.trialConversion') }}</span><strong>{{ d.trial_conversion_rate === null || d.trial_conversion_rate === undefined ? '—' : `${Math.round(d.trial_conversion_rate * 1000) / 10} %` }}</strong>
            </div>
            <div class="cr-aside__row">
              <span>{{ t('cockpit.revenue.trials') }}</span><strong>{{ d.trialing_subscriptions ?? '—' }}</strong>
            </div>
            <VBtn
              block
              size="small"
              variant="tonal"
              class="mt-3"
              :to="{ name: 'platform-billing-tab', params: { tab: 'subscriptions' } }"
            >
              {{ t('cockpit.revenue.subscriptions') }}
            </VBtn>
          </div>
        </div>
      </template>
    </AppWidgetShell>
  </div>
</template>

<style scoped>
.cr-kpis { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .5rem 1.75rem; margin-block-end: .5rem; }
.cr-kpis--tight { gap: .5rem 1rem; }
.cr-kpi { display: flex; flex-direction: column; min-inline-size: 0; }
.cr-kpi__label { font-size: .8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.cr-kpi__value { font-size: 2rem; font-weight: 700; letter-spacing: -.03em; line-height: 1.1; color: rgb(var(--v-theme-on-surface)); white-space: nowrap; }
.cr-kpi__value--sm { font-size: 1.25rem; font-weight: 600; letter-spacing: -.015em; }
.cr-kpi__delta { font-size: .8125rem; font-weight: 600; display: inline-flex; align-items: center; gap: .25rem; white-space: nowrap; }
.cr-compare { display: inline-flex; align-items: center; gap: .5rem; font-size: .8125rem; padding: .3rem .625rem; border-radius: 8px; color: rgba(var(--v-theme-on-surface), .65); border: 1px dashed rgba(var(--v-border-color), .35); transition: background .18s, color .18s; }
.cr-compare--on { color: rgb(var(--v-theme-on-surface)); background: rgba(var(--v-theme-on-surface), .05); }
.cr-compare__swatch { inline-size: 16px; border-block-start: 2px dashed currentColor; }
.cr-chart { cursor: pointer; margin-inline: -.5rem; }
.cr-aside { flex: 0 0 220px; border-inline-start: 1px solid rgba(var(--v-border-color), .12); padding-inline-start: 1.25rem; }
.cr-aside__row { display: flex; justify-content: space-between; gap: .5rem; padding-block: .5rem; font-size: .875rem; border-block-end: 1px dashed rgba(var(--v-border-color), .16); }
</style>
