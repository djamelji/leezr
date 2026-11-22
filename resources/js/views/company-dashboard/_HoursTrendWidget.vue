<script setup>
// ADR-856 (Leezr 5.0 L4) — « Heures travaillées » (workforce.hours_trend). Real data : { labels[7], hours[7] } (7 days).
//   KPI size  → total of the week + bars sparkline
//   standard  → total / average / best day + bar chart
//   expanded  → + per-day list beside the chart
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'company' }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const kpiSize = computed(() => rootW.value > 0 && (rootH.value < 200 || rootW.value < 300))
const d = computed(() => props.data || {})
const hours = computed(() => (d.value.hours || []).map(Number))
const labels = computed(() => d.value.labels || [])
const total = computed(() => Math.round(hours.value.reduce((a, b) => a + b, 0) * 10) / 10)
const active = computed(() => hours.value.filter(h => h > 0))
const avg = computed(() => (active.value.length ? Math.round((total.value / active.value.length) * 10) / 10 : 0))
const peakIdx = computed(() => hours.value.indexOf(Math.max(...hours.value, 0)))
const has = computed(() => hours.value.length > 0)
const state = computed(() => (props.loading && !props.data ? 'loading' : d.value.error ? 'error' : !has.value || total.value === 0 ? 'empty' : 'ready'))
const h1 = v => `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(v)} h`
const to = { name: 'company-workforce-time' }
const chartH = h => Math.max(140, Math.min(380, Math.floor((h - 150) / 30) * 30))
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <AppKpiTile
      v-if="kpiSize"
      bare
      :label="t('workforce.widgets.hoursPanelTitle')"
      icon="tabler-clock-hour-4"
      color="info"
      :value="state === 'ready' ? h1(total) : null"
      :context="state === 'ready' ? `${t('workforce.widgets.hoursAvg')} : ${h1(avg)}` : ''"
      :empty-hint="t('workforce.widgets.hoursEmpty')"
      :spark="hours"
      spark-type="bar"
      :loading="state === 'loading'"
      :error="state === 'error'"
      :to="to"
    />
    <AppWidgetShell
      v-else
      bare
      :title="t('workforce.widgets.hoursPanelTitle')"
      :subtitle="t('workforce.widgets.hoursPanelSub')"
      icon="tabler-clock-hour-4"
      color="info"
      :link="t('l4.kpi.seeClock')"
      :to="to"
      :state="state"
      empty-icon="tabler-clock-off"
      :empty-title="t('workforce.widgets.hoursEmpty')"
    >
      <template #default="{ mode, h }">
        <div class="ht-kpis">
          <div><span>{{ t('workforce.widgets.hoursTotal') }}</span><strong class="ht-big">{{ h1(total) }}</strong></div>
          <div><span>{{ t('workforce.widgets.hoursAvg') }}</span><strong>{{ h1(avg) }}</strong></div>
          <div v-if="mode !== 'compact'">
            <span>{{ t('workforce.widgets.hoursPeak') }}</span><strong>{{ labels[peakIdx] }} · {{ h1(hours[peakIdx] || 0) }}</strong>
          </div>
        </div>
        <div class="d-flex gap-5 flex-grow-1 min-w-0">
          <div class="flex-grow-1 min-w-0">
            <LzrApexChart
              type="bar"
              :series="[{ name: t('workforce.widgets.hoursPanelTitle'), data: hours }]"
              :categories="labels"
              :height="chartH(h)"
              :colors="['info']"
              :highlight-index="peakIdx"
              :format-y="v => h1(v)"
            />
          </div>
          <div
            v-if="mode === 'expanded'"
            class="ht-days"
          >
            <div
              v-for="(l, i) in labels"
              :key="i"
              class="ht-day"
              :class="{ 'ht-day--peak': i === peakIdx }"
            >
              <span>{{ l }}</span><strong>{{ h1(hours[i] || 0) }}</strong>
            </div>
          </div>
        </div>
      </template>
    </AppWidgetShell>
  </div>
</template>

<style scoped>
.ht-kpis { display: flex; flex-wrap: wrap; gap: .5rem 1.75rem; margin-block-end: .5rem; }
.ht-kpis > div { display: flex; flex-direction: column; font-size: .8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.ht-kpis strong { font-size: 1.125rem; color: rgb(var(--v-theme-on-surface)); }
.ht-kpis .ht-big { font-size: 1.75rem; letter-spacing: -.02em; }
.ht-days { flex: 0 0 170px; border-inline-start: 1px solid rgba(var(--v-border-color), .12); padding-inline-start: 1rem; display: flex; flex-direction: column; gap: .125rem; }
.ht-day { display: flex; justify-content: space-between; font-size: .875rem; padding-block: .25rem; }
.ht-day--peak { color: rgb(var(--v-theme-info)); font-weight: 600; }
</style>
