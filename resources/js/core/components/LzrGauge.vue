<script setup>
// Leezr Premium — jauge radiale avec seuils sémantiques (Phase 0, ADR-741).
import { computed } from 'vue'
import { useTheme } from 'vuetify'
import VueApexCharts from 'vue3-apexcharts'

const props = defineProps({
  value: { type: Number, default: 0 },        // 0..100
  label: { type: String, default: '' },
  height: { type: [Number, String], default: 200 },
  // seuils : en dessous de `danger` => error, en dessous de `warn` => warning, sinon success
  danger: { type: Number, default: 50 },
  warn: { type: Number, default: 80 },
  invert: { type: Boolean, default: false },   // true : un score BAS est mauvais (taux de conformité)
})

const vuetifyTheme = useTheme()
const tc = computed(() => vuetifyTheme.current.value.colors)

const severity = computed(() => {
  const v = props.value
  if (props.invert) {
    if (v < props.danger) return 'error'
    if (v < props.warn) return 'warning'

    return 'success'
  }
  if (v > props.warn) return 'error'
  if (v > props.danger) return 'warning'

  return 'success'
})

const color = computed(() => tc.value[severity.value])

const options = computed(() => ({
  chart: { sparkline: { enabled: true }, fontFamily: 'inherit' },
  colors: [color.value],
  plotOptions: {
    radialBar: {
      hollow: { size: '62%' },
      track: { background: `rgba(${['error', 'warning', 'success'].includes(severity.value) ? '0,0,0' : '0,0,0'},0.08)`, strokeWidth: '100%' },
      dataLabels: {
        name: { show: !!props.label, fontSize: '13px', offsetY: 22, color: tc.value['on-surface'] },
        value: { fontSize: '28px', fontWeight: 600, offsetY: -14, color: color.value, formatter: v => `${Math.round(v)}%` },
      },
    },
  },
  stroke: { lineCap: 'round' },
  labels: props.label ? [props.label] : [],
}))

const series = computed(() => [Math.max(0, Math.min(100, props.value))])
</script>

<template>
  <VueApexCharts type="radialBar" :height="height" :options="options" :series="series" />
</template>
