<script setup>
// ADR-856 (Leezr 5.0 L4) — billing daily trends (billing.revenue_trend « Facturé par jour (HT) », billing.cashflow_trend_30d
// « Encaissements par jour (TTC) »), view_billing. Data unchanged : { currency, chart: { labels, series (cents) } }.
//   KPI size → period total + sparkline  ·  standard → total, average, best day + chart  ·  expanded → + best days list
// The error state is an error (it used to say « no data »).
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { money } from './cockpitFormat'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  title: { type: String, required: true },
  icon: { type: String, default: 'tabler-chart-line' },
  color: { type: String, default: 'primary' },
  to: { type: Object, required: true },
  linkLabel: { type: String, default: '' },
})

const { t, locale } = useI18n()
const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const kpiSize = computed(() => rootW.value > 0 && (rootH.value < 200 || rootW.value < 300))
const d = computed(() => props.data || {})
const series = computed(() => (d.value.chart?.series || []).map(Number))
const labels = computed(() => d.value.chart?.labels || [])
const cur = computed(() => (d.value.currency && d.value.currency !== 'MULTI' ? d.value.currency : null))
const total = computed(() => series.value.reduce((a, b) => a + b, 0))
const activeDays = computed(() => series.value.filter(v => v > 0).length)
const avg = computed(() => (activeDays.value ? Math.round(total.value / activeDays.value) : 0))
const best = computed(() => series.value.map((v, i) => ({ v, i })).filter(x => x.v > 0).sort((a, b) => b.v - a.v).slice(0, 5))
const fmtDate = s => { try { return new Date(s).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) } catch { return s } }
const state = computed(() => (props.loading && !props.data ? 'loading' : d.value.error ? 'error' : !series.value.length || total.value === 0 ? 'empty' : 'ready'))
const m = v => (cur.value ? money(v, cur.value) : (v / 100).toFixed(2))
const chartH = h => Math.max(140, Math.min(400, Math.floor((h - 150) / 30) * 30))
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <AppKpiTile
      v-if="kpiSize"
      bare
      :label="title"
      :icon="icon"
      :color="color"
      :value="state === 'ready' ? m(total) : null"
      :context="state === 'ready' ? t('l4.trend.period', { n: series.length }) : ''"
      :empty-hint="t('l4.trend.empty')"
      :spark="series.map(v => Math.round(v / 100))"
      :loading="state === 'loading'"
      :error="state === 'error'"
      :to="to"
    />
    <AppWidgetShell
      v-else
      bare
      :title="title"
      :subtitle="t('l4.trend.period', { n: series.length || 30 })"
      :icon="icon"
      :color="color"
      :link="linkLabel"
      :to="to"
      :state="state"
      :empty-title="t('l4.trend.empty')"
    >
      <template #default="{ mode, h }">
        <div class="bt-kpis">
          <div><span>{{ t('l4.trend.total') }}</span><strong class="bt-big">{{ m(total) }}</strong></div>
          <div><span>{{ t('l4.trend.avg') }}</span><strong>{{ m(avg) }}</strong></div>
          <div v-if="mode !== 'compact' && best[0]">
            <span>{{ t('l4.trend.best') }}</span><strong>{{ fmtDate(labels[best[0].i]) }} · {{ m(best[0].v) }}</strong>
          </div>
        </div>
        <div class="d-flex gap-5 flex-grow-1 min-w-0">
          <div class="flex-grow-1 min-w-0">
            <LzrApexChart
              type="area"
              :series="[{ name: title, data: series }]"
              :categories="labels.map(fmtDate)"
              :height="chartH(h)"
              :colors="[color]"
              :format-y="v => m(v)"
            />
          </div>
          <div
            v-if="mode === 'expanded'"
            class="bt-best"
          >
            <div class="text-overline text-medium-emphasis">
              {{ t('l4.trend.bestDays') }}
            </div>
            <div
              v-for="b in best"
              :key="b.i"
              class="bt-row"
            >
              <span>{{ fmtDate(labels[b.i]) }}</span><strong>{{ m(b.v) }}</strong>
            </div>
          </div>
        </div>
      </template>
    </AppWidgetShell>
  </div>
</template>

<style scoped>
.bt-kpis { display: flex; flex-wrap: wrap; gap: .5rem 1.75rem; margin-block-end: .5rem; }
.bt-kpis > div { display: flex; flex-direction: column; font-size: .8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.bt-kpis strong { font-size: 1.125rem; color: rgb(var(--v-theme-on-surface)); white-space: nowrap; }
.bt-kpis .bt-big { font-size: 1.75rem; letter-spacing: -.02em; }
.bt-best { flex: 0 0 190px; border-inline-start: 1px solid rgba(var(--v-border-color), .12); padding-inline-start: 1rem; }
.bt-row { display: flex; justify-content: space-between; font-size: .875rem; padding-block: .3rem; border-block-end: 1px dashed rgba(var(--v-border-color), .16); }
</style>
