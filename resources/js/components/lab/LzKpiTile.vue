<script setup>
// Leezr 5.0 DESIGN LAB — KPI tile (prototype). Size-aware through the EXISTING presentation engine
// (useSurfacePresentation / resolvePresentationMode, ADR-748/750): the content changes with the real size of the
// container, never a tiny value lost in a big card.
//   micro    → value + delta
//   compact  → + label and icon
//   standard → + sparkline
//   wide / expanded → bigger sparkline + context line
import { useTheme } from 'vuetify'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { useCountUp } from './useCountUp'

const props = defineProps({
  label: { type: String, required: true },
  icon: { type: String, default: 'tabler-chart-line' },
  value: { type: Number, required: true },
  format: { type: String, default: 'number' }, // number | eur | pct
  decimals: { type: Number, default: 0 },
  delta: { type: Number, default: null }, // % vs previous period
  deltaLabel: { type: String, default: '' },
  context: { type: String, default: '' },
  color: { type: String, default: 'primary' },
  spark: { type: Array, default: () => [] },
  sparkType: { type: String, default: 'area' }, // area | bar
  accent: { type: Boolean, default: false }, // filled hero tile
  to: { type: [String, Object], default: null },
})

const root = ref(null)

// Same measurement as the engine (ResizeObserver on the real container) ; the KPI FAMILY has its own size profile:
// the chart thresholds (micro < 210 px high) would leave every KPI tile in « micro » (proposal 04, §size profiles).
const { rootW, rootH } = useSurfacePresentation(root)

const mode = computed(() => {
  const w = rootW.value, h = rootH.value
  if (!w) return 'standard'
  if (w < 190 || h < 96) return 'micro'
  if (w < 215) return 'compact'
  if (w >= 520) return h >= 220 ? 'expanded' : 'wide'

  return 'standard'
})

const shown = useCountUp(toRef(props, 'value'))
const theme = useTheme()

const fmt = n => {
  const opts = { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals }
  if (props.format === 'eur') return new Intl.NumberFormat('fr-FR', { ...opts, style: 'currency', currency: 'EUR' }).format(n)
  if (props.format === 'pct') return `${new Intl.NumberFormat('fr-FR', opts).format(n)} %`

  return new Intl.NumberFormat('fr-FR', opts).format(n)
}

const display = computed(() => fmt(shown.value))
const up = computed(() => (props.delta ?? 0) >= 0)
const showSpark = computed(() => props.spark.length > 1 && !['micro', 'compact'].includes(mode.value))
const big = computed(() => ['wide', 'expanded'].includes(mode.value))

const chartOptions = computed(() => {
  const c = theme.current.value.colors
  const col = props.accent ? '#ffffff' : c[props.color] || c.primary

  return {
    chart: { sparkline: { enabled: true }, animations: { enabled: true, speed: 500, dynamicAnimation: { enabled: false } } },
    stroke: { curve: 'smooth', width: props.sparkType === 'area' ? 2.5 : 0 },
    fill: props.sparkType === 'area' ? { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02 } } : { opacity: 0.9 },
    plotOptions: { bar: { borderRadius: 3, columnWidth: '55%' } },
    colors: [col],
    tooltip: { enabled: true, theme: theme.current.value.dark ? 'dark' : 'light', x: { show: false }, y: { formatter: v => fmt(v), title: { formatter: () => '' } } },
  }
})
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <VCard
      :to="to || undefined"
      class="lz-kpi lzr-card lzr-card--raised h-100"
      :class="[`lz-kpi--${mode}`, { 'lz-kpi--accent': accent }]"
      :color="accent ? color : undefined"
      :variant="accent ? 'flat' : 'elevated'"
    >
      <div class="lz-kpi__body">
        <div
          v-if="mode === 'micro'"
          class="lz-kpi__label text-truncate mb-1"
        >
          {{ label }}
        </div>
        <div
          v-else
          class="d-flex align-center gap-2 mb-2"
        >
          <VAvatar
            :color="accent ? 'white' : color"
            variant="tonal"
            rounded="lg"
            size="34"
          >
            <VIcon
              :icon="icon"
              size="20"
            />
          </VAvatar>
          <span class="lz-kpi__label text-truncate">{{ label }}</span>
          <VSpacer />
          <VChip
            v-if="delta !== null && big"
            size="small"
            :color="accent ? 'white' : (up ? 'success' : 'error')"
            variant="tonal"
            label
          >
            <VIcon
              start
              size="14"
              :icon="up ? 'tabler-trending-up' : 'tabler-trending-down'"
            />{{ up ? '+' : '' }}{{ delta }} %
          </VChip>
        </div>

        <div class="d-flex align-end gap-3">
          <div class="flex-shrink-0">
            <div class="lz-kpi__value lzr-kpi__value">
              {{ display }}
            </div>
            <div
              v-if="delta !== null && !big"
              class="lz-kpi__delta"
              :class="accent ? '' : (up ? 'text-success' : 'text-error')"
            >
              <VIcon
                size="14"
                :icon="up ? 'tabler-arrow-up-right' : 'tabler-arrow-down-right'"
              />
              {{ up ? '+' : '' }}{{ delta }} %
              <span
                v-if="deltaLabel && mode !== 'micro'"
                class="lz-kpi__muted"
              >{{ deltaLabel }}</span>
            </div>
          </div>
          <div
            v-if="showSpark"
            class="lz-kpi__spark flex-grow-1"
          >
            <VueApexCharts
              :key="`${mode}-${sparkType}`"
              :type="sparkType"
              :height="big ? 72 : 48"
              :options="chartOptions"
              :series="[{ name: label, data: spark }]"
            />
          </div>
        </div>

        <div
          v-if="context && big"
          class="lz-kpi__muted mt-2"
        >
          {{ context }}
        </div>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.lz-kpi { overflow: hidden; min-block-size: 150px; }
.lz-kpi__body { padding: 1rem 1.125rem; block-size: 100%; display: flex; flex-direction: column; justify-content: space-between; }
.lz-kpi__label { font-size: .875rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.lz-kpi--accent .lz-kpi__label, .lz-kpi--accent .lz-kpi__muted { color: rgba(255, 255, 255, .82); }
.lz-kpi__value { white-space: nowrap; font-size: clamp(1.5rem, 2.2vw, 2rem); }
.lz-kpi--micro .lz-kpi__value { white-space: nowrap; font-size: 1.5rem; }
.lz-kpi--expanded .lz-kpi__value, .lz-kpi--wide .lz-kpi__value { white-space: nowrap; font-size: 2.375rem; }
.lz-kpi__delta { font-size: .8125rem; font-weight: 600; display: flex; align-items: center; gap: .25rem; margin-block-start: .25rem; white-space: nowrap; }
.lz-kpi__muted { font-size: .8125rem; font-weight: 400; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.lz-kpi__spark { min-inline-size: 90px; margin-block-end: -6px; }
</style>
