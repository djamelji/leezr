<script setup>
// Leezr 5.0 (L0/L1, ADR-855) — KPI tile. Size-aware with the KPI family profile (familyModes.resolveKpiMode, same
// measurement as the Presentation Engine) : the content is recomposed, never a tiny value lost in a big card.
//   micro    → label + value
//   compact  → + icon + delta
//   standard → + sparkline
//   wide / expanded → bigger sparkline + context line + delta chip
// A missing value (null) is shown as « — » with its reason : never a fabricated 0.
import { useTheme } from 'vuetify'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { resolveKpiMode } from '@/core/surface-engine/familyModes'
import { useCountUp } from '@/composables/useCountUp'

const props = defineProps({
  label: { type: String, required: true },
  icon: { type: String, default: 'tabler-chart-line' },
  value: { type: Number, default: null },
  format: { type: String, default: 'number' }, // number | money (cents) | pct
  currency: { type: String, default: 'EUR' },
  decimals: { type: Number, default: 0 },
  delta: { type: Number, default: null }, // % vs previous period
  deltaLabel: { type: String, default: '' },
  upIsGood: { type: Boolean, default: true },
  context: { type: String, default: '' },
  emptyHint: { type: String, default: '' },
  color: { type: String, default: 'primary' },
  spark: { type: Array, default: () => [] },
  sparkType: { type: String, default: 'area' }, // area | bar
  accent: { type: Boolean, default: false }, // filled brand tile
  bare: { type: Boolean, default: false }, // inside a DashboardGrid tile (the grid draws the card)
  to: { type: [String, Object], default: null },
})

const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const mode = computed(() => resolveKpiMode(rootW.value, rootH.value))

const shown = useCountUp(computed(() => props.value ?? 0))
const theme = useTheme()

const fmt = n => {
  const o = { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals }
  if (props.format === 'money') return new Intl.NumberFormat('fr-FR', { ...o, style: 'currency', currency: props.currency }).format((Number(n) || 0) / 100)
  if (props.format === 'pct') return `${new Intl.NumberFormat('fr-FR', o).format(n)} %`

  return new Intl.NumberFormat('fr-FR', o).format(n)
}

const hasValue = computed(() => props.value !== null && props.value !== undefined)
const display = computed(() => (hasValue.value ? fmt(shown.value) : '—'))
const up = computed(() => (props.delta ?? 0) >= 0)
const good = computed(() => (up.value === props.upIsGood) || props.delta === 0)
const deltaText = computed(() => `${up.value ? '+' : ''}${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(props.delta ?? 0)} %`)
const showSpark = computed(() => hasValue.value && props.spark.length > 1 && !['micro', 'compact'].includes(mode.value))
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
    <component
      :is="to ? 'RouterLink' : 'div'"
      :to="to || undefined"
      class="app-kpi h-100"
      :class="[`app-kpi--${mode}`, { 'app-kpi--accent': accent, 'app-kpi--card lzr-card lzr-card--raised': !bare, 'app-kpi--link': !!to }]"
      :style="accent ? { '--kpi-accent': `var(--v-theme-${color})` } : undefined"
    >
      <div class="app-kpi__body">
        <div
          v-if="mode === 'micro'"
          class="app-kpi__label text-truncate"
        >
          {{ label }}
        </div>
        <div
          v-else
          class="d-flex align-center gap-2"
        >
          <VAvatar
            :color="accent ? 'white' : color"
            variant="tonal"
            rounded="lg"
            :size="mode === 'compact' ? 30 : 34"
          >
            <VIcon
              :icon="icon"
              :size="mode === 'compact' ? 17 : 19"
            />
          </VAvatar>
          <span
            class="app-kpi__label"
            :class="mode === 'compact' ? 'app-kpi__label--wrap' : 'text-truncate'"
          >{{ label }}</span>
          <VSpacer />
          <VChip
            v-if="delta !== null && big && hasValue"
            size="small"
            :color="accent ? 'white' : (good ? 'success' : 'error')"
            variant="tonal"
            label
          >
            <VIcon
              start
              size="14"
              :icon="up ? 'tabler-trending-up' : 'tabler-trending-down'"
            />{{ deltaText }}
          </VChip>
        </div>

        <div class="d-flex align-end gap-3 min-w-0">
          <div class="flex-shrink-0 min-w-0">
            <div class="app-kpi__value lzr-kpi__value">
              {{ display }}
            </div>
            <div
              v-if="!hasValue && emptyHint && mode !== 'micro'"
              class="app-kpi__muted text-truncate"
            >
              {{ emptyHint }}
            </div>
            <div
              v-else-if="delta !== null && !big && hasValue"
              class="app-kpi__delta"
              :class="accent ? '' : (good ? 'text-success' : 'text-error')"
            >
              <VIcon
                size="14"
                :icon="up ? 'tabler-arrow-up-right' : 'tabler-arrow-down-right'"
              />
              {{ deltaText }}
              <span
                v-if="deltaLabel && mode !== 'micro'"
                class="app-kpi__muted"
              >{{ deltaLabel }}</span>
            </div>
          </div>
          <div
            v-if="showSpark"
            class="app-kpi__spark flex-grow-1"
          >
            <VueApexCharts
              :key="`${mode}-${sparkType}`"
              :type="sparkType"
              :height="big ? 72 : 46"
              :options="chartOptions"
              :series="[{ name: label, data: spark }]"
            />
          </div>
        </div>

        <div
          v-if="context && hasValue && (big || (mode === 'standard' && delta === null))"
          class="app-kpi__muted text-truncate"
        >
          {{ context }}
        </div>
      </div>
    </component>
  </div>
</template>

<style scoped>
.app-kpi { display: block; overflow: hidden; color: inherit; text-decoration: none; border-radius: 12px; }
.app-kpi--card { background: rgb(var(--v-theme-surface)); }
.app-kpi--accent {
  color: #fff;
  background:
    radial-gradient(120% 120% at 100% 0%, rgba(255, 255, 255, .18) 0%, transparent 55%),
    linear-gradient(135deg, rgb(var(--kpi-accent)) 0%, color-mix(in srgb, rgb(var(--kpi-accent)) 70%, #0b1530) 100%) !important;
}
.app-kpi--link { cursor: pointer; transition: background .18s; }
.app-kpi--link:not(.app-kpi--card):hover { background: rgba(var(--v-theme-on-surface), .03); }
.app-kpi__body { padding: 1rem 1.125rem; block-size: 100%; display: flex; flex-direction: column; justify-content: space-between; gap: .375rem; }
.app-kpi--micro .app-kpi__body { padding: .75rem .875rem; }
.app-kpi__label { font-size: .875rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-kpi__label--wrap { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.25; }
.app-kpi--accent .app-kpi__label, .app-kpi--accent .app-kpi__muted { color: rgba(255, 255, 255, .84); }
.app-kpi__value { white-space: nowrap; font-size: 1.875rem; color: rgb(var(--v-theme-on-surface)); }
.app-kpi--accent .app-kpi__value { color: #fff; }
.app-kpi--micro .app-kpi__value, .app-kpi--compact .app-kpi__value { font-size: 1.5rem; }
.app-kpi--expanded .app-kpi__value, .app-kpi--wide .app-kpi__value { font-size: 2.375rem; }
.app-kpi__delta { font-size: .8125rem; font-weight: 600; display: flex; align-items: center; gap: .25rem; margin-block-start: .25rem; white-space: nowrap; }
.app-kpi__muted { font-size: .8125rem; font-weight: 400; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-kpi__spark { min-inline-size: 80px; margin-block-end: -6px; }
</style>
