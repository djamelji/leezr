<script setup>
// Leezr Premium — graphique ApexCharts theme-aware (Phase 0, ADR-741).
// Réutilise le pattern _RevenueTrendWidget (useTheme + hexToRgb) ; aucune
// nouvelle dépendance (vue3-apexcharts déjà installé). Modes S/M/L.
import { computed } from 'vue'
import { useTheme } from 'vuetify'
import { hexToRgb } from '@layouts/utils'
import VueApexCharts from 'vue3-apexcharts'

const props = defineProps({
  type: { type: String, default: 'area' }, // area | line | bar | donut | radial
  series: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  labels: { type: Array, default: () => [] },
  height: { type: [Number, String], default: 220 },
  colors: { type: Array, default: null },
  mode: { type: String, default: 'full' }, // spark | standard | full
  formatY: { type: Function, default: null },
})

const vuetifyTheme = useTheme()
const tc = computed(() => vuetifyTheme.current.value.colors)
const tv = computed(() => vuetifyTheme.current.value.variables)

// Résout un nom sémantique Vuetify ('success'…) en hex réel ; ApexCharts ne sait pas
// interpréter une CSS var (rgb(var(--v-theme-…))) → on passe toujours des hex.
const SEMANTIC = ['primary', 'secondary', 'success', 'warning', 'error', 'info']
function resolveColor(c) {
  if (!c) return c
  if (SEMANTIC.includes(c)) return tc.value[c]

  return c
}
const palette = computed(() => (props.colors || ['primary', 'success', 'warning', 'info']).map(resolveColor))
const isSpark = computed(() => props.mode === 'spark')
const labelColor = computed(() => `rgba(${hexToRgb(tc.value['on-surface'])},${tv.value['medium-emphasis-opacity']})`)
const borderColor = computed(() => `rgba(${hexToRgb(String(tv.value['border-color']).startsWith('#') ? tv.value['border-color'] : tc.value['on-surface'])},${tv.value['border-opacity']})`)

const options = computed(() => {
  const base = {
    chart: {
      parentHeightOffset: 0,
      toolbar: { show: false },
      sparkline: { enabled: isSpark.value },
      fontFamily: 'inherit',
      animations: { enabled: true, speed: 500 },
    },
    colors: palette.value,
    tooltip: { enabled: !isSpark.value, theme: vuetifyTheme.current.value.dark ? 'dark' : 'light' },
    dataLabels: { enabled: false },
    legend: { show: !isSpark.value && (props.type === 'donut' || props.type === 'radial'), labels: { colors: labelColor.value } },
    grid: {
      show: !isSpark.value && (props.type === 'area' || props.type === 'line' || props.type === 'bar'),
      borderColor: borderColor.value,
      strokeDashArray: 6,
      padding: isSpark.value ? { top: 0, bottom: 0, left: 0, right: 0 } : { top: -10 },
    },
  }

  if (props.type === 'area' || props.type === 'line') {
    return {
      ...base,
      stroke: { curve: 'smooth', width: props.type === 'line' ? 3 : 2 },
      fill: props.type === 'area'
        ? { type: 'gradient', gradient: { shadeIntensity: 0.9, opacityFrom: 0.5, opacityTo: 0.05, stops: [0, 95] } }
        : { type: 'solid', opacity: 1 },
      xaxis: {
        categories: props.categories,
        labels: { show: !isSpark.value, style: { colors: labelColor.value, fontSize: '12px' } },
        axisBorder: { show: false }, axisTicks: { show: false },
      },
      yaxis: { labels: { show: !isSpark.value, style: { colors: labelColor.value }, formatter: props.formatY || undefined } },
    }
  }

  if (props.type === 'bar') {
    return {
      ...base,
      plotOptions: { bar: { borderRadius: 6, columnWidth: '42%', borderRadiusApplication: 'end' } },
      xaxis: {
        categories: props.categories,
        labels: { show: !isSpark.value, style: { colors: labelColor.value, fontSize: '12px' } },
        axisBorder: { show: false }, axisTicks: { show: false },
      },
      yaxis: { labels: { show: !isSpark.value, style: { colors: labelColor.value }, formatter: props.formatY || undefined } },
    }
  }

  // donut
  return {
    ...base,
    labels: props.labels,
    stroke: { width: 0 },
    plotOptions: { pie: { donut: { size: '72%', labels: { show: !isSpark.value, value: { color: labelColor.value }, total: { show: true, label: 'Total', color: labelColor.value } } } } },
  }
})
</script>

<template>
  <VueApexCharts
    :type="type === 'radial' ? 'radialBar' : type"
    :height="height"
    :options="options"
    :series="series"
  />
</template>
