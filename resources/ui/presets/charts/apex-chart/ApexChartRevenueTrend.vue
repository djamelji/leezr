<script setup>
/**
 * Preset: ApexChartRevenueTrend
 *
 * Area chart optimized for financial KPI trends (MRR, ARR, revenue).
 * Uses Vuexy theme-aware colors via hexToRgb + vuetify variables.
 * Adapts fully to dark/light mode (grid, labels, tooltip).
 *
 * Props:
 *   - labels:  string[] — x-axis categories (e.g. month names)
 *   - series:  number[] — y-axis data points
 *   - color:   string   — Vuetify color key (default: 'primary')
 *   - height:  number   — chart height in px (default: 280)
 *   - yFormatter: function — optional y-axis / tooltip value formatter
 *
 * Usage:
 *   <ApexChartRevenueTrend
 *     :labels="['Jan', 'Feb', 'Mar']"
 *     :series="[1000, 1200, 1100]"
 *     :y-formatter="val => formatMoney(val)"
 *   />
 */
import VueApexCharts from 'vue3-apexcharts'
import { hexToRgb } from '@layouts/utils'
import { useTheme } from 'vuetify'

const props = defineProps({
  labels: { type: Array, default: () => [] },
  series: { type: Array, default: () => [] },
  seriesName: { type: String, default: 'Value' },
  color: { type: String, default: 'primary' },
  height: { type: [Number, String], default: 280 },
  yFormatter: { type: Function, default: null },
})

const vuetifyTheme = useTheme()

const chartOptions = computed(() => {
  const colors = vuetifyTheme.current.value.colors
  const vars = vuetifyTheme.current.value.variables
  const borderColor = `rgba(${hexToRgb(String(vars['border-color']))},${vars['border-opacity']})`
  const disabledText = `rgba(${hexToRgb(colors['on-surface'])},${vars['disabled-opacity']})`

  const formatValue = props.yFormatter || (v => v)

  return {
    chart: {
      parentHeightOffset: 0,
      toolbar: { show: false },
      animations: { enabled: false },
    },
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    colors: [colors[props.color] || colors.primary],
    fill: {
      type: 'gradient',
      gradient: { shadeIntensity: 0.8, opacityFrom: 0.6, opacityTo: 0.1 },
    },
    grid: {
      borderColor,
      strokeDashArray: 4,
      padding: { top: -15, left: 12, right: 0, bottom: -5 },
    },
    xaxis: {
      categories: props.labels,
      axisBorder: { show: false },
      axisTicks: { show: false },
      crosshairs: { show: false },
      labels: {
        show: true,
        rotate: 0,
        style: { fontSize: '12px', colors: disabledText },
      },
    },
    yaxis: {
      labels: {
        formatter: formatValue,
        style: { fontSize: '12px', colors: disabledText },
      },
    },
    tooltip: {
      theme: false,
      marker: { show: false },
      x: { show: false },
      custom: ({ series: s, seriesIndex, dataPointIndex }) => {
        const val = s[seriesIndex][dataPointIndex]
        const label = props.labels[dataPointIndex] || ''

        return `<div style="background:rgb(var(--v-theme-surface));color:rgb(var(--v-theme-on-surface));border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));border-radius:6px;padding:10px 14px;box-shadow:0 4px 12px rgba(0,0,0,0.1);font-family:inherit;">
          <div style="font-size:12px;opacity:0.5;margin-bottom:2px;">${label}</div>
          <div style="font-weight:600;font-size:14px;">${formatValue(val)}</div>
        </div>`
      },
    },
    legend: { show: false },
  }
})

const chartSeries = computed(() => [{
  name: props.seriesName,
  data: props.series,
}])
</script>

<template>
  <VueApexCharts
    type="area"
    :height="height"
    :options="chartOptions"
    :series="chartSeries"
  />
</template>
