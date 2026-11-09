<script setup>
// ADR-747 (P2) — hero « Pilotage commercial » : grand graphique immersif MRR (niveau),
// sélecteur de période (Semaine/Mois/Année) + navigation + comparatif N-1.
// MRR = NIVEAU recalculé par bucket (service PlatformCommercialSeriesService). Données réelles.
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useTheme } from 'vuetify'
import { hexToRgb } from '@layouts/utils'
import VueApexCharts from 'vue3-apexcharts'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { $platformApi } from '@/utils/platformApi'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const theme = useTheme()

// ── État interactif ──
const d = computed(() => props.data || {})
const series = ref(d.value.series || null)      // série courante (depuis resolve() puis re-fetch)
const period = ref(series.value?.period || 'month')
const anchor = ref(null)                         // null = période courante
const compare = ref(false)
const fetching = ref(false)
const fetchError = ref(false)

watch(() => d.value.series, s => { if (s && !anchor.value && period.value === s.period) series.value = s })

async function fetchSeries() {
  fetching.value = true; fetchError.value = false
  try {
    const q = new URLSearchParams({ period: period.value, compare: compare.value ? '1' : '0' })
    if (anchor.value) q.set('anchor', anchor.value)
    series.value = await $platformApi(`/billing/commercial-series?${q.toString()}`)
    await nextTick(); renderKey.value++   // remount propre du graphe sur nouvelles données
  } catch (e) { fetchError.value = true }
  finally { fetching.value = false }
}

function setPeriod(p) { if (p === period.value) return; period.value = p; anchor.value = null; fetchSeries() }
function nav(dir) {
  const s = series.value; if (!s) return
  if (dir > 0 && !s.can_next) return
  const base = new Date(anchor.value || Date.now())
  if (period.value === 'week') base.setDate(base.getDate() + dir * 7)
  else if (period.value === 'year') base.setFullYear(base.getFullYear() + dir)
  else base.setMonth(base.getMonth() + dir)
  anchor.value = base.toISOString(); fetchSeries()
}
function today() { anchor.value = null; fetchSeries() }
function toggleCompare() { compare.value = !compare.value; fetchSeries() }

// ── Formatage ──
const currency = computed(() => series.value?.currency || d.value.currency || 'EUR')
const money = cents => ((Number(cents) || 0) / 100).toLocaleString('fr-FR', { style: 'currency', currency: currency.value, maximumFractionDigits: 0 })
const pct = v => `${v >= 0 ? '+' : ''}${Math.round(v * 100)}%`

// ── Chart : dimensions pilotées par le conteneur (ResizeObserver) ──
const chartHost = ref(null)
const cw = ref(0); const ch = ref(0)
const renderKey = ref(0)
let ro = null; let bump = null
const ready = computed(() => cw.value > 40 && ch.value > 40)
onMounted(() => {
  if (!chartHost.value) return
  ro = new ResizeObserver(es => {
    const r = es[0]?.contentRect; if (!r) return
    const nw = Math.round(r.width); const nh = Math.round(r.height)
    const changed = Math.abs(nw - cw.value) >= 16 || Math.abs(nh - ch.value) >= 16
    cw.value = nw; ch.value = nh
    // Remount propre d'ApexCharts si la zone change nettement (resize widget / drag / layout).
    if (changed && cw.value > 40 && ch.value > 40) { clearTimeout(bump); bump = setTimeout(() => renderKey.value++, 80) }
  })
  ro.observe(chartHost.value)
})
onBeforeUnmount(() => { if (ro) ro.disconnect(); clearTimeout(bump) })

// ── Séries & options ApexCharts ──
const curData = computed(() => (series.value?.current || []).map(v => v === null ? null : Math.round(v / 100)))
const prevData = computed(() => (series.value?.previous || []).map(v => v === null ? null : Math.round(v / 100)))
const showPrev = computed(() => compare.value && series.value?.has_history && prevData.value.length > 0)
const labels = computed(() => series.value?.labels || [])
const hasData = computed(() => curData.value.some(v => v !== null && v > 0) || showPrev.value)

const apexSeries = computed(() => {
  const s = [{ name: t('platform.widgets.commercial.current'), data: curData.value }]
  if (showPrev.value) s.push({ name: t('platform.widgets.commercial.previous'), data: prevData.value })
  return s
})

const chartOptions = computed(() => {
  const c = theme.current.value.colors
  const v = theme.current.value.variables
  const label = `rgba(${hexToRgb(c['on-surface'])},${v['medium-emphasis-opacity']})`
  const grid = `rgba(${hexToRgb(c['on-surface'])},${v['border-opacity']})`
  const primary = c.primary
  const n = labels.value.length
  return {
    chart: { type: 'area', parentHeightOffset: 0, toolbar: { show: false }, animations: { enabled: false }, fontFamily: 'inherit' },
    colors: [primary, `rgba(${hexToRgb(c['on-surface'])},0.45)`],
    stroke: { curve: 'smooth', width: showPrev.value ? [3, 2] : [3], dashArray: showPrev.value ? [0, 5] : [0] },
    fill: { type: showPrev.value ? ['gradient', 'solid'] : ['gradient'], gradient: { shadeIntensity: 0.8, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 95] }, opacity: showPrev.value ? [0.35, 0] : [0.35] },
    dataLabels: { enabled: false },
    legend: { show: showPrev.value, position: 'top', horizontalAlign: 'right', fontSize: '12px', labels: { colors: label }, markers: { width: 10, height: 10 }, itemMargin: { horizontal: 8 } },
    grid: { show: true, borderColor: grid, strokeDashArray: 5, padding: { top: showPrev.value ? 0 : -6, right: 6, bottom: -4, left: 6 }, xaxis: { lines: { show: false } } },
    xaxis: {
      categories: labels.value,
      tickAmount: n > 12 ? Math.min(12, Math.floor(cw.value / 60)) : undefined,
      labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: label, fontSize: '11px' } },
      axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false },
    },
    yaxis: { labels: { style: { colors: label, fontSize: '11px' }, formatter: val => money(val * 100) }, tickAmount: 4 },
    tooltip: {
      shared: true, theme: theme.current.value.dark ? 'dark' : 'light',
      y: { formatter: val => val === null ? '—' : money(val * 100) },
      x: { formatter: (_, { dataPointIndex }) => labels.value[dataPointIndex] ?? '' },
    },
    markers: { size: 0, hover: { size: 4 } },
  }
})

const periods = [
  { key: 'week', label: 'platform.widgets.commercial.week' },
  { key: 'month', label: 'platform.widgets.commercial.month' },
  { key: 'year', label: 'platform.widgets.commercial.year' },
]
const summary = computed(() => series.value?.summary || {})
const deltaPct = computed(() => summary.value.delta_pct)
const isCompact = computed(() => props.viewport?.density === 'S')
</script>

<template>
  <!-- Mode compact (densité S / mobile : grille forcée à h=2) -->
  <div v-if="isCompact" class="widget-root commercial-widget d-flex flex-column pa-4">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0">
      <span class="text-body-2 text-medium-emphasis text-truncate">{{ t('platform.widgets.commercialOverview') }}</span>
      <VAvatar color="primary" variant="tonal" size="34" rounded><VIcon icon="tabler-chart-line" size="18" /></VAvatar>
    </div>
    <div class="text-h5 font-weight-medium text-high-emphasis mt-1">{{ money(d.mrr) }}</div>
    <div class="text-caption text-medium-emphasis">MRR · {{ money(d.arr) }} ARR</div>
    <div class="flex-grow-1 d-flex align-end">
      <LzrApexChart v-if="hasData" class="w-100" type="area" mode="spark" :series="[{ name: 'MRR', data: curData }]" :height="44" />
    </div>
  </div>

  <div v-else class="widget-root commercial-widget d-flex flex-column">
    <!-- En-tête : titre + période + navigation + comparatif -->
    <div class="cw-head d-flex align-center flex-wrap gap-2 px-5 pt-4">
      <div class="me-auto">
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.commercialOverview') }}</div>
        <div class="text-caption text-medium-emphasis">{{ series?.label || t('platform.widgets.commercialSub') }}<span v-if="series?.partial"> · {{ t('platform.widgets.commercial.partial') }}</span></div>
      </div>

      <VBtnToggle :model-value="period" density="compact" variant="outlined" divided mandatory class="cw-periods" @update:model-value="setPeriod">
        <VBtn v-for="p in periods" :key="p.key" :value="p.key" size="small">{{ t(p.label) }}</VBtn>
      </VBtnToggle>

      <div class="d-flex align-center">
        <VBtn icon variant="text" size="x-small" :aria-label="t('platform.widgets.commercial.prev')" @click="nav(-1)"><VIcon icon="tabler-chevron-left" size="18" /></VBtn>
        <VBtn variant="text" size="x-small" :disabled="!anchor" @click="today">{{ t('platform.widgets.commercial.today') }}</VBtn>
        <VBtn icon variant="text" size="x-small" :disabled="!series?.can_next" :aria-label="t('platform.widgets.commercial.next')" @click="nav(1)"><VIcon icon="tabler-chevron-right" size="18" /></VBtn>
      </div>

      <VBtn :variant="compare ? 'tonal' : 'text'" :color="compare ? 'primary' : undefined" size="small" prepend-icon="tabler-git-compare" @click="toggleCompare">
        {{ t('platform.widgets.commercial.compareN1') }}
      </VBtn>
    </div>

    <!-- Corps : graphique immersif + stats latérales (responsive par largeur de widget) -->
    <div class="cw-body d-flex flex-grow-1">
      <div class="cw-chart">
        <div ref="chartHost" class="cw-chart-host">
          <VProgressCircular v-if="(loading || fetching) && !series" indeterminate size="28" class="cw-center" />
          <div v-else-if="fetchError || series?.error" class="cw-center text-center">
            <VIcon icon="tabler-alert-circle" color="error" size="26" class="mb-1" /><div class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.loadError') }}</div>
          </div>
          <div v-else-if="!hasData" class="cw-center text-center">
            <VIcon icon="tabler-chart-line" size="28" class="text-disabled mb-2" /><div class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.commercial.noData') }}</div>
          </div>
          <VueApexCharts v-else-if="ready" :key="renderKey" type="area" :width="cw" :height="ch" :options="chartOptions" :series="apexSeries" />
          <div v-if="compare && !series?.has_history && hasData" class="cw-note text-caption text-medium-emphasis">
            <VIcon icon="tabler-info-circle" size="14" /> {{ t('platform.widgets.commercial.noHistoryN1') }}
          </div>
        </div>
      </div>

      <!-- Stats latérales -->
      <div class="cw-aside flex-shrink-0 d-flex flex-column gap-3 px-5 py-4">
        <div>
          <div class="d-flex align-baseline gap-2">
            <span class="text-h5 font-weight-medium text-high-emphasis">{{ money(d.mrr) }}</span>
            <span v-if="showPrev && deltaPct !== null" class="text-caption font-weight-medium" :class="deltaPct >= 0 ? 'text-success' : 'text-error'">{{ pct(deltaPct) }}</span>
          </div>
          <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.commercial.mrrNow') }}</div>
        </div>
        <VDivider />
        <div class="d-flex flex-column gap-3">
          <div v-for="s in [
            { i:'tabler-businessplan', c:'success', l: t('platform.widgets.commercial.arr'), val: money(d.arr) },
            { i:'tabler-repeat', c:'primary', l: t('platform.widgets.activeSubs', { n: d.active_subscriptions ?? 0 }).replace(/^\d+\s/, ''), val: d.active_subscriptions ?? 0 },
            { i:'tabler-hourglass', c:'info', l: t('platform.widgets.commercial.trials'), val: d.trialing_subscriptions ?? 0 },
          ]" :key="s.l" class="d-flex align-center gap-2">
            <VAvatar :color="s.c" variant="tonal" size="30" rounded><VIcon :icon="s.i" size="16" /></VAvatar>
            <div class="overflow-hidden">
              <div class="text-body-2 font-weight-medium text-high-emphasis text-truncate">{{ s.val }}</div>
              <div class="text-caption text-medium-emphasis text-truncate">{{ s.l }}</div>
            </div>
          </div>
        </div>
        <div v-if="showPrev && summary.mrr_prev_equiv !== null" class="mt-auto">
          <VDivider class="mb-2" />
          <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.commercial.vsN1') }}</div>
          <div class="text-body-2 text-high-emphasis">{{ money(summary.mrr_prev_equiv) }}<span v-if="summary.delta_abs !== null" class="text-caption ms-1" :class="summary.delta_abs >= 0 ? 'text-success' : 'text-error'">({{ summary.delta_abs >= 0 ? '+' : '' }}{{ money(summary.delta_abs) }})</span></div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; }
.cw-head { row-gap: 8px; }
.cw-periods :deep(.v-btn) { text-transform: none; }
.cw-body { min-block-size: 0; }
.cw-chart { flex: 1 1 auto; min-inline-size: 0; display: flex; flex-direction: column; }
.cw-chart-host { position: relative; flex: 1 1 auto; min-block-size: 120px; padding-inline: 8px; }
.cw-note { position: absolute; inset-inline: 12px; inset-block-end: 2px; pointer-events: none; }
.cw-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.cw-aside { inline-size: 210px; border-inline-start: 1px solid rgba(var(--v-theme-on-surface), 0.08); }

/* Format intermédiaire : stats en bandeau sous le graphe */
@container dashboard-tile (max-width: 680px) {
  .cw-body { flex-direction: column; }
  .cw-aside { inline-size: 100%; border-inline-start: none; border-block-start: 1px solid rgba(var(--v-theme-on-surface), 0.08); flex-direction: row; flex-wrap: wrap; align-items: center; }
  .cw-aside > * { flex: 1 1 auto; }
}
/* Format étroit : en-tête compact */
@container dashboard-tile (max-width: 420px) {
  .cw-head { padding-block-top: 12px; }
  .cw-periods :deep(.v-btn) { min-inline-size: 0; padding-inline: 8px; }
}
</style>
