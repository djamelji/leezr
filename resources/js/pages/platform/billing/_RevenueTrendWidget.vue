<script setup>
import { hexToRgb } from '@layouts/utils'
import { useTheme } from 'vuetify'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
})

const { t, locale } = useI18n()
const vuetifyTheme = useTheme()

// ── Visual mode — driven by WIDTH (grid columns), not height ──
const mode = computed(() => {
  const w = props.viewport?.w || 12
  if (w <= 4) return 'S'
  if (w <= 6) return 'M'

  return 'L'
})

// Height density — controls spacing only
const hDensity = computed(() => props.viewport?.density || 'L')

// Fewer horizontal grid lines when widget is short
const yTicks = computed(() => hDensity.value === 'S' ? 3 : 5)

// Date tick count based on grid columns — predictable, no truncation
const xTicks = computed(() => {
  const w = props.viewport?.w || 12
  if (w <= 4) return 2
  if (w <= 6) return 2
  if (w <= 8) return 5
  return 7
})

const avatarSize = computed(() => mode.value === 'S' ? 28 : mode.value === 'M' ? 34 : 40)
const iconSize = computed(() => mode.value === 'S' ? 16 : mode.value === 'M' ? 20 : 22)

// ── Date formatting — locale-aware via Intl, safe YYYY-MM-DD parsing ──

function parseDate(val) {
  if (!val) return null
  const parts = String(val).split('-')
  if (parts.length === 3) return new Date(+parts[0], +parts[1] - 1, +parts[2])

  return new Date(val)
}

function formatAxisL(val) {
  const d = parseDate(val)
  if (!d || isNaN(d)) return ''

  return d.toLocaleDateString(locale.value, { day: 'numeric', month: 'short' })
}

function formatAxisM(val) {
  const d = parseDate(val)
  if (!d || isNaN(d)) return ''

  return d.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit' })
}

function formatTooltipDate(val) {
  const d = parseDate(val)
  if (!d || isNaN(d)) return ''

  return d.toLocaleDateString(locale.value, { day: 'numeric', month: 'long', year: 'numeric' })
}

function formatCurrencyValue(val) {
  const cur = props.data?.currency
  if (!cur || cur === 'MULTI') return val.toFixed(2)

  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: cur }).format(val)
}

// ── Chart options by visual mode (width-driven) ──

const chartOptions = computed(() => {
  const colors = vuetifyTheme.current.value.colors
  const borderColor = `rgba(${hexToRgb(String(vuetifyTheme.current.value.variables['border-color']))},${vuetifyTheme.current.value.variables['border-opacity']})`
  const disabledText = `rgba(${hexToRgb(colors['on-surface'])},${vuetifyTheme.current.value.variables['disabled-opacity']})`

  const labels = props.data?.chart?.labels || []

  const tooltipConfig = {
    theme: false,
    marker: { show: false },
    x: { show: false },
    custom: ({ series, seriesIndex, dataPointIndex }) => {
      const val = series[seriesIndex][dataPointIndex]
      const rawDate = labels[dataPointIndex] || ''

      return `<div style="background:rgb(var(--v-theme-surface));color:rgb(var(--v-theme-on-surface));border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));border-radius:6px;padding:10px 14px;box-shadow:0 4px 12px rgba(0,0,0,0.1);font-family:inherit;">
        <div style="font-size:12px;opacity:0.5;margin-bottom:2px;">${formatTooltipDate(rawDate)}</div>
        <div style="font-weight:600;font-size:14px;">${formatCurrencyValue(val)}</div>
      </div>`
    },
  }

  const baseChart = {
    parentHeightOffset: 0,
    toolbar: { show: false },
    redrawOnParentResize: true,
    animations: { enabled: false },
  }

  const baseStroke = { curve: 'smooth', width: 2 }

  const baseFill = {
    type: 'gradient',
    gradient: { shadeIntensity: 0.8, opacityFrom: 0.6, opacityTo: 0.1 },
  }

  // ── S: sparkline — no axes, no grid, no dates ──
  if (mode.value === 'S') {
    return {
      chart: { ...baseChart, sparkline: { enabled: true } },
      dataLabels: { enabled: false },
      stroke: baseStroke,
      colors: [colors.primary],
      fill: baseFill,
      grid: { show: false, padding: { top: 0, bottom: 0, left: 0, right: 0 } },
      xaxis: {
        show: false,
        categories: labels,
        labels: { show: false },
        axisBorder: { show: false },
        axisTicks: { show: false },
        crosshairs: { show: false },
        tooltip: { enabled: false },
      },
      yaxis: { show: false },
      tooltip: tooltipConfig,
      legend: { show: false },
    }
  }

  // ── Shared xaxis config for M/L ──
  const xaxisBase = {
    categories: labels,
    axisBorder: { show: false },
    axisTicks: { show: false },
    crosshairs: { show: false },
    tooltip: { enabled: false },
  }

  // ── M: dashed grid ──
  if (mode.value === 'M') {
    return {
      chart: baseChart,
      dataLabels: { enabled: false },
      stroke: baseStroke,
      colors: [colors.primary],
      fill: baseFill,
      grid: { borderColor, strokeDashArray: 4, padding: { top: -15, left: 12, right: 0, bottom: -5 } },
      xaxis: {
        ...xaxisBase,
        tickAmount: xTicks.value,
        labels: {
          show: true,
          rotate: 0,
          trim: false,
          hideOverlappingLabels: false,
          style: { fontSize: '11px', colors: disabledText },
          formatter: formatAxisM,
        },
      },
      yaxis: { show: false, tickAmount: yTicks.value },
      tooltip: tooltipConfig,
      legend: { show: false },
    }
  }

  // ── L: solid grid, locale dates ──
  return {
    chart: baseChart,
    dataLabels: { enabled: false },
    stroke: baseStroke,
    colors: [colors.primary],
    fill: baseFill,
    grid: { borderColor, padding: { top: -15, left: 12, right: 0, bottom: -5 } },
    xaxis: {
      ...xaxisBase,
      tickAmount: xTicks.value,
      labels: {
        show: true,
        rotate: 0,
        trim: false,
        hideOverlappingLabels: true,
        style: { fontSize: '12px', colors: disabledText },
        formatter: formatAxisM,
      },
    },
    yaxis: { show: false, tickAmount: yTicks.value },
    tooltip: tooltipConfig,
    legend: { show: false },
  }
})

const chartSeries = computed(() => [
  {
    name: t('platformBilling.widgets.revenueTrend'),
    data: props.data?.chart?.series || [],
  },
])

// ── KPI = period total (sum) ──

const summaryValue = computed(() => {
  const series = props.data?.chart?.series || []
  if (!series.length) return null
  const total = series.reduce((acc, v) => acc + v, 0)
  const cur = props.data?.currency
  if (!cur || cur === 'MULTI') return total.toFixed(0)

  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(total)
})
</script>

<template>
  <div
    class="widget-root"
    :class="[`mode-${mode}`, `h-${hDensity}`]"
  >
    <!-- Header — icon + title, KPI right for M/L -->
    <div class="d-flex align-center gap-2 overflow-hidden widget-header">
      <VAvatar
        color="primary"
        variant="tonal"
        :size="avatarSize"
        rounded
      >
        <VIcon
          icon="tabler-chart-line"
          :size="iconSize"
        />
      </VAvatar>
      <span class="widget-title text-medium-emphasis">
        {{ t('platformBilling.widgets.revenueTrend') }}
      </span>
      <VChip
        v-if="data?.currency === 'MULTI'"
        size="x-small"
        variant="tonal"
        color="warning"
        class="flex-shrink-0"
      >
        {{ t('platformDashboard.engine.multiCurrency') }}
      </VChip>
      <VSpacer v-if="mode !== 'S' && summaryValue" />
      <span
        v-if="mode !== 'S' && summaryValue"
        class="widget-kpi font-weight-bold flex-shrink-0"
      >
        {{ summaryValue }}
      </span>
    </div>

    <!-- Chart body -->
    <div class="widget-body">
      <VSkeletonLoader
        v-if="loading"
        type="image"
      />
      <div
        v-else-if="!data || !data.chart?.series?.length"
        class="d-flex align-center justify-center h-100 text-disabled"
      >
        {{ t('platformBilling.widgets.noData') }}
      </div>
      <VueApexCharts
        v-else
        :key="`chart-${mode}`"
        type="area"
        height="100%"
        :options="chartOptions"
        :series="chartSeries"
      />
    </div>

    <!-- KPI bottom-right for S -->
    <div
      v-if="mode === 'S' && summaryValue"
      class="widget-footer"
    >
      <span class="widget-kpi font-weight-bold">
        {{ summaryValue }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.widget-root {
  height: 100%;
  display: flex;
  flex-direction: column;
}

/* ── Spacing ── */

.h-S { padding: 4px 0 6px 0; }
.h-M { padding: 8px 0 10px 0; }
.h-L { padding: 12px 0 16px 0; }

.widget-header { flex: 0 0 auto; }
.h-S .widget-header { padding-inline: 6px; margin-bottom: 0; }
.h-M .widget-header { padding-inline: 10px; margin-bottom: 4px; }
.h-L .widget-header { padding-inline: 16px; margin-bottom: 8px; }

.widget-title {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.widget-body {
  flex: 1 1 auto;
  min-height: 0;
}

.widget-footer {
  flex: 0 0 auto;
  display: flex;
  justify-content: flex-end;
  padding: 2px 10px 0;
}

/* ── Typography scale — monotone S < M < L ── */

.mode-S .widget-title { font-size: 12px; }
.mode-M .widget-title { font-size: 16px; }
.mode-L .widget-title { font-size: 18px; }

.mode-S .widget-kpi { font-size: 14px; }
.mode-M .widget-kpi { font-size: 28px; }
.mode-L .widget-kpi { font-size: 34px; }
</style>
