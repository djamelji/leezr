<script setup>
// Leezr Premium — graphique ApexCharts theme-aware (Phase 0, ADR-741 ; refonte ADR-745).
// Réutilise le pattern _RevenueTrendWidget (useTheme + hexToRgb) ; aucune
// nouvelle dépendance (vue3-apexcharts déjà installé). Modes S/M/L.
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useTheme } from 'vuetify'
import { hexToRgb } from '@layouts/utils'
import VueApexCharts from 'vue3-apexcharts'
import { thinCategories } from '@/core/surface-engine/axisLabels'

const props = defineProps({
  type: { type: String, default: 'area' }, // area | line | bar | donut | radial
  legend: { type: Boolean, default: true }, // ADR-856 — false when the widget draws its own legend (Apex then reserves no space for it)
  series: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  labels: { type: Array, default: () => [] },
  height: { type: [Number, String], default: 220 },
  colors: { type: Array, default: null },
  mode: { type: String, default: 'full' }, // spark | standard | full
  formatY: { type: Function, default: null },

  // Bar uniquement : met une barre en relief (jour pic), les autres atténuées — pattern Vuexy EarningReports.
  highlightIndex: { type: Number, default: -1 },

  // Area/line : 2ᵉ série (N-1) rendue en pointillé sans remplissage + légende (ADR-748).
  compare: { type: Boolean, default: false },
})

// ── Fiabilisation du rendu en cellule de grille (ADR-745) ──
// Les graphes area/bar mesuraient une largeur nulle au montage (cellule
// positionnée dynamiquement) → graphe invisible. On monte le graphe UNIQUEMENT
// quand le conteneur a une largeur mesurable, puis on pilote la largeur par un
// prop RÉACTIF (VueApexCharts se redimensionne sans remonter → pas de clignotement
// blanc lors d'un resize, ex. capture fullPage).
// ADR-748b — RESIZE fiable : le graphe a `width:'100%'` (ApexCharts mesure son parent)
// et on lui demande de se remesurer en émettant un event `resize` fenêtre DÉBOUNCÉ à
// chaque changement de conteneur (ApexCharts l'écoute nativement). On ne pilote plus la
// largeur par un prop réactif (source des disparitions de canvas). `measuredW` ne sert
// qu'à GATER le 1er montage (une fois le conteneur mesurable) et ne retombe jamais à 0.
const hostRef = ref(null)
const measuredW = ref(0)

// ADR-856 (L4) — category labels thinned to the measured width (≈ one per 70 px, no rotation) : no overlapping dates.
// The tooltip keeps the full label of every point.
const axisCategories = computed(() => thinCategories(props.categories, measuredW.value))
const tooltipX = { formatter: (v, o) => (o && props.categories?.[o.dataPointIndex] !== undefined ? props.categories[o.dataPointIndex] : v) }

// instanceKey : remount PROPRE du graphe à la STABILISATION d'un resize (ADR-748b).
// ApexCharts perd parfois son canvas quand son conteneur change fortement (passage
// mobile de la grille, re-layout) et ne le recrée pas de lui-même → récupération jamais
// garantie. On remonte donc une seule fois, APRÈS stabilisation (débouncé), ce qui
// garantit la récupération pour TOUS les consommateurs (donut/bar/area) sans migration.
// La clé est un COMPTEUR (pas une fonction des dimensions) → identité stable pendant le
// drag, un seul remount à l'arrêt.
const instanceKey = ref(0)
let ro = null; let rTimer = null; let settleTimer = null

const ready = computed(() => measuredW.value > 0)

// ADR-813 — vue3-apexcharts 1.5.3 handles a change of its `height`/`width`/`type` props by destroying the
// chart WITHOUT recreating it (its init returns early while the destroyed instance is still referenced) :
// the chart went blank and the next options update threw « Cannot read properties of null (reading
// 'forceXAxisUpdate') ». Widgets compute their height after layout (chartPx) → such a change remounts
// the chart cleanly instead. ApexCharts' destroy() also leaves its 150 ms resize timer armed, which then
// re-renders the destroyed instance (« attribute height: NaN ») : it is cancelled before every remount.
const chartKey = computed(() => `${instanceKey.value}-${props.type}-${props.height}`)
const apexRef = ref(null)

const disarm = () => {
  const g = apexRef.value?.chart?.w?.globals
  if (g?.resizeTimer) { clearTimeout(g.resizeTimer); g.resizeTimer = null }
}

watch(chartKey, disarm, { flush: 'pre' })
onBeforeUnmount(disarm)

onMounted(() => {
  if (!hostRef.value) return
  ro = new ResizeObserver(entries => {
    const w = Math.round(entries[0]?.contentRect?.width || 0)
    if (!w) return
    const first = !measuredW.value

    measuredW.value = w
    if (first) return

    // Conteneur redimensionné → (1) remesure native ApexCharts (débouncé, pendant le drag),
    clearTimeout(rTimer)
    rTimer = setTimeout(() => { try { window.dispatchEvent(new Event('resize')) } catch (e) { /* noop */ } }, 130)
    // (2) remount propre à la stabilisation → récupération garantie si le canvas a été perdu.
    clearTimeout(settleTimer)
    settleTimer = setTimeout(() => { instanceKey.value++ }, 240)
  })
  ro.observe(hostRef.value)
})

onBeforeUnmount(() => { if (ro) ro.disconnect(); clearTimeout(rTimer); clearTimeout(settleTimer) })

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

      // Animations désactivées : rendu instantané et stable (évite le clignotement
      // blanc lors d'un resize / d'une capture fullPage). ADR-745.
      animations: { enabled: false },
    },
    colors: palette.value,
    tooltip: { enabled: !isSpark.value, theme: vuetifyTheme.current.value.dark ? 'dark' : 'light' },
    dataLabels: { enabled: false },
    legend: { show: props.legend && !isSpark.value && (props.type === 'donut' || props.type === 'radial'), labels: { colors: labelColor.value } },
    grid: {
      show: !isSpark.value && (props.type === 'area' || props.type === 'line' || props.type === 'bar'),
      borderColor: borderColor.value,
      strokeDashArray: 6,
      padding: isSpark.value ? { top: 0, bottom: 0, left: 0, right: 0 } : { top: -10 },
    },
  }

  if (props.type === 'area' || props.type === 'line') {
    // Comparatif N-1 : série 0 pleine (gradient), série 1 pointillée sans remplissage.
    const dual = props.compare && (props.series?.length || 0) >= 2
    if (dual) {
      return {
        ...base,
        colors: [tc.value.primary, `rgba(${hexToRgb(tc.value['on-surface'])},0.45)`],
        stroke: { curve: 'smooth', width: [2.5, 2], dashArray: [0, 5] },
        fill: { type: ['gradient', 'solid'], gradient: { shadeIntensity: 0.8, opacityFrom: 0.35, opacityTo: 0.03, stops: [0, 95] }, opacity: [0.35, 0] },
        legend: { show: !isSpark.value, position: 'top', horizontalAlign: 'right', fontSize: '12px', labels: { colors: labelColor.value }, markers: { width: 9, height: 9 }, itemMargin: { horizontal: 8 } },
        tooltip: { ...base.tooltip, shared: true, x: tooltipX, y: { formatter: props.formatY || undefined } },
        xaxis: { categories: axisCategories.value, labels: { show: !isSpark.value, rotate: 0, hideOverlappingLabels: true, style: { colors: labelColor.value, fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
        yaxis: { labels: { show: !isSpark.value, style: { colors: labelColor.value }, formatter: props.formatY || undefined } },
      }
    }
    
    return {
      ...base,
      stroke: { curve: 'smooth', width: props.type === 'line' ? 3 : 2 },
      fill: props.type === 'area'
        ? { type: 'gradient', gradient: { shadeIntensity: 0.9, opacityFrom: 0.5, opacityTo: 0.05, stops: [0, 95] } }
        : { type: 'solid', opacity: 1 },
      tooltip: { ...base.tooltip, x: tooltipX, y: { formatter: props.formatY || undefined } },
      xaxis: {
        categories: axisCategories.value,
        labels: { show: !isSpark.value, rotate: 0, hideOverlappingLabels: true, style: { colors: labelColor.value, fontSize: '12px' } },
        axisBorder: { show: false }, axisTicks: { show: false },
      },
      yaxis: { labels: { show: !isSpark.value, style: { colors: labelColor.value }, formatter: props.formatY || undefined } },
    }
  }

  if (props.type === 'bar') {
    const hl = props.highlightIndex
    const n = (props.series?.[0]?.data || []).length
    const distributed = hl >= 0 && n > 0
    const primaryHex = tc.value.primary

    const distColors = distributed
      ? Array.from({ length: n }, (_, i) => i === hl ? primaryHex : `rgba(${hexToRgb(primaryHex)},0.16)`)
      : palette.value

    
    return {
      ...base,
      colors: distColors,
      plotOptions: { bar: { borderRadius: 6, columnWidth: distributed ? '38%' : '42%', borderRadiusApplication: 'end', distributed } },
      legend: { show: false },
      tooltip: { ...base.tooltip, enabled: true, x: tooltipX, y: { formatter: props.formatY || undefined } },
      xaxis: {
        categories: axisCategories.value,
        labels: { show: !isSpark.value, rotate: 0, style: { colors: labelColor.value, fontSize: '12px' } },
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
  <div
    ref="hostRef"
    class="lzr-apexchart-host"
    :style="{ inlineSize: '100%', blockSize: typeof height === 'number' ? height + 'px' : height }"
  >
    <VueApexCharts
      v-if="ready"
      ref="apexRef"
      :key="chartKey"
      :type="type === 'radial' ? 'radialBar' : type"
      width="100%"
      :height="height"
      :options="options"
      :series="series"
    />
  </div>
</template>
