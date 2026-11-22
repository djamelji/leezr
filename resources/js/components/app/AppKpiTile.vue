<script setup>
// Leezr 5.0 (L0/L1 ADR-855, L4 ADR-856) — KPI tile. Size-aware with the KPI family profile (familyModes.resolveKpiMode,
// same measurement as the Presentation Engine). The content is RECOMPOSED per size, never a small value stretched:
//   micro    → label + value
//   compact  → icon, label, value, change OR context
//   standard → + sparkline, context, action
//   wide     → + the breakdown next to the value (row)
//   expanded → big value, breakdown grid, full-width sparkline, action
// States : loading (skeleton of the same shape), error (no value ever shown, retry), empty (« — » + reason).
import { useTheme } from 'vuetify'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { resolveKpiMode } from '@/core/surface-engine/familyModes'
import { useCountUp } from '@/composables/useCountUp'

const props = defineProps({
  label: { type: String, required: true },
  icon: { type: String, default: 'tabler-chart-line' },
  value: { type: [Number, String], default: null },
  format: { type: String, default: 'number' }, // number | money (cents) | pct
  currency: { type: String, default: 'EUR' },
  decimals: { type: Number, default: 0 },
  delta: { type: Number, default: null }, // % vs previous period
  deltaLabel: { type: String, default: '' },
  upIsGood: { type: Boolean, default: true },
  context: { type: String, default: '' },
  emptyHint: { type: String, default: '' },
  breakdown: { type: Array, default: () => [] }, // [{ label, value, tone?, to? }]
  color: { type: String, default: 'primary' },
  spark: { type: Array, default: () => [] },
  sparkType: { type: String, default: 'area' }, // area | bar
  accent: { type: Boolean, default: false },
  bare: { type: Boolean, default: false }, // inside a DashboardGrid tile (the grid draws the card)
  to: { type: [String, Object], default: null },
  linkLabel: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  error: { type: Boolean, default: false },
})

const emit = defineEmits(['retry'])
const slots = useSlots()

// The whole tile is a link only when it holds no other interactive element (no nested links / buttons).
const tileLink = computed(() => !!props.to && !props.error && !slots.action)
const { t } = useI18n()


// ADR-856 — « Retry » : the parent may handle it (@retry) ; the dashboards also listen to this event to re-resolve
// their widgets through the existing engine (a widget never fetches its batch data itself).
const retry = () => {
  emit('retry')
  window.dispatchEvent(new CustomEvent('lzr-dashboard-retry'))
}

const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const mode = computed(() => resolveKpiMode(rootW.value, rootH.value))

const numeric = computed(() => typeof props.value === 'number')
const shown = useCountUp(computed(() => (numeric.value ? props.value : 0)))
const theme = useTheme()

const fmt = n => {
  const o = { minimumFractionDigits: props.decimals, maximumFractionDigits: props.decimals }
  if (props.format === 'money') return new Intl.NumberFormat('fr-FR', { ...o, style: 'currency', currency: props.currency }).format((Number(n) || 0) / 100)
  if (props.format === 'pct') return `${new Intl.NumberFormat('fr-FR', o).format(n)} %`

  return new Intl.NumberFormat('fr-FR', o).format(n)
}

const hasValue = computed(() => !props.error && props.value !== null && props.value !== undefined && props.value !== '')
const display = computed(() => (!hasValue.value ? '—' : numeric.value ? fmt(shown.value) : String(props.value)))
const up = computed(() => (props.delta ?? 0) >= 0)
const good = computed(() => (up.value === props.upIsGood) || props.delta === 0)
const deltaText = computed(() => `${up.value ? '+' : ''}${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(props.delta ?? 0)} %`)
const showSpark = computed(() => hasValue.value && props.spark.length > 1 && ['standard', 'wide', 'expanded'].includes(mode.value))
const big = computed(() => ['wide', 'expanded'].includes(mode.value))
const showBreakdown = computed(() => hasValue.value && props.breakdown.length && big.value)
const showContext = computed(() => !props.error && props.context && mode.value !== 'micro' && (props.delta === null || big.value))

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
      :is="tileLink ? 'RouterLink' : 'div'"
      :to="tileLink ? to : undefined"
      class="app-kpi h-100"
      :class="[`app-kpi--${mode}`, { 'app-kpi--accent': accent, 'app-kpi--card lzr-card lzr-card--raised': !bare, 'app-kpi--link': tileLink }]"
      :style="accent ? { '--kpi-accent': `var(--v-theme-${color})` } : undefined"
    >
      <div class="app-kpi__body">
        <!-- header -->
        <div
          v-if="mode === 'micro'"
          class="app-kpi__label text-truncate"
        >
          {{ label }}
        </div>
        <div
          v-else
          class="d-flex align-center gap-2 min-w-0"
        >
          <VAvatar
            :color="error ? 'error' : accent ? 'white' : color"
            variant="tonal"
            rounded="lg"
            :size="mode === 'compact' ? 30 : 34"
          >
            <VIcon
              :icon="error ? 'tabler-plug-connected-x' : icon"
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
            class="flex-shrink-0"
          >
            <VIcon
              start
              size="14"
              :icon="up ? 'tabler-trending-up' : 'tabler-trending-down'"
            />{{ deltaText }}
          </VChip>
        </div>

        <!-- loading : same shape -->
        <div
          v-if="loading && !hasValue && !error"
          class="app-kpi__skeleton"
        >
          <div class="app-kpi__sk app-kpi__sk--value" />
          <div
            v-if="mode !== 'micro'"
            class="app-kpi__sk app-kpi__sk--line"
          />
        </div>

        <!-- error : never a value -->
        <div
          v-else-if="error"
          class="app-kpi__error"
        >
          <span class="text-error font-weight-medium">{{ t('appWidget.errorTitle') }}</span>
          <VBtn
            v-if="mode !== 'micro'"
            size="x-small"
            variant="tonal"
            color="error"
            prepend-icon="tabler-refresh"
            @click.prevent.stop="retry"
          >
            {{ t('appWidget.retry') }}
          </VBtn>
        </div>

        <!-- value -->
        <template v-else>
          <div
            class="d-flex align-end gap-3 min-w-0"
            :class="{ 'app-kpi__main--row': mode === 'wide' && showBreakdown }"
          >
            <div class="flex-shrink-0 min-w-0">
              <div class="app-kpi__value lzr-kpi__value">
                {{ display }}
              </div>
              <div
                v-if="!hasValue && emptyHint && mode !== 'micro'"
                class="app-kpi__muted app-kpi__clamp"
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
              v-if="mode === 'wide' && showBreakdown"
              class="app-kpi__breakdown app-kpi__breakdown--row"
            >
              <component
                :is="b.to ? 'RouterLink' : 'div'"
                v-for="b in breakdown"
                :key="b.label"
                :to="b.to || undefined"
                class="app-kpi__stat"
              >
                <span
                  class="app-kpi__stat-value"
                  :class="b.tone ? `text-${b.tone}` : ''"
                >{{ b.value }}</span>
                <span class="app-kpi__stat-label">{{ b.label }}</span>
              </component>
            </div>
            <div
              v-if="showSpark && mode !== 'expanded'"
              class="app-kpi__spark flex-grow-1"
            >
              <VueApexCharts
                :key="`${mode}-${sparkType}`"
                :type="sparkType"
                :height="big ? 64 : 46"
                :options="chartOptions"
                :series="[{ name: label, data: spark }]"
              />
            </div>
          </div>

          <div
            v-if="mode === 'expanded' && showBreakdown"
            class="app-kpi__breakdown"
          >
            <component
              :is="b.to ? 'RouterLink' : 'div'"
              v-for="b in breakdown"
              :key="b.label"
              :to="b.to || undefined"
              class="app-kpi__stat app-kpi__stat--card"
            >
              <span
                class="app-kpi__stat-value"
                :class="b.tone ? `text-${b.tone}` : ''"
              >{{ b.value }}</span>
              <span class="app-kpi__stat-label">{{ b.label }}</span>
            </component>
          </div>
          <div
            v-if="showSpark && mode === 'expanded'"
            class="app-kpi__spark app-kpi__spark--full"
          >
            <VueApexCharts
              :key="`x-${sparkType}`"
              :type="sparkType"
              height="70"
              :options="chartOptions"
              :series="[{ name: label, data: spark }]"
            />
          </div>

          <div
            v-if="showContext || $slots.action || (linkLabel && to && mode === 'expanded')"
            class="app-kpi__foot"
          >
            <span
              v-if="showContext"
              class="app-kpi__muted app-kpi__clamp"
            >{{ context }}</span>
            <VSpacer />
            <slot name="action" />
            <component
              :is="tileLink ? 'span' : 'RouterLink'"
              v-if="linkLabel && to && mode === 'expanded'"
              :to="tileLink ? undefined : to"
              class="app-kpi__link"
            >
              {{ linkLabel }} <VIcon
                icon="tabler-arrow-right"
                size="15"
              />
            </component>
          </div>
        </template>
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
.app-kpi--link:not(.app-kpi--card):hover { background: rgba(var(--v-theme-on-surface), .025); }
.app-kpi__body { padding: 1rem 1.125rem; block-size: 100%; display: flex; flex-direction: column; justify-content: space-between; gap: .375rem; min-block-size: 0; }
.app-kpi--micro .app-kpi__body { padding: .75rem .875rem; }
.app-kpi--expanded .app-kpi__body { justify-content: flex-start; gap: .75rem; }
.app-kpi__label { font-size: .875rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); min-inline-size: 0; }
.app-kpi__label--wrap { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.25; }
.app-kpi--accent .app-kpi__label, .app-kpi--accent .app-kpi__muted { color: rgba(255, 255, 255, .84); }
.app-kpi__value { white-space: nowrap; font-size: 1.875rem; color: rgb(var(--v-theme-on-surface)); }
.app-kpi--accent .app-kpi__value { color: #fff; }
.app-kpi--micro .app-kpi__value, .app-kpi--compact .app-kpi__value { font-size: 1.5rem; }
.app-kpi--wide .app-kpi__value { font-size: 2.25rem; }
.app-kpi--expanded .app-kpi__value { font-size: 2.75rem; }
.app-kpi__delta { font-size: .8125rem; font-weight: 600; display: flex; align-items: center; gap: .25rem; margin-block-start: .25rem; white-space: nowrap; }
.app-kpi__muted { font-size: .8125rem; font-weight: 400; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-kpi__clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.app-kpi--compact .app-kpi__clamp, .app-kpi--micro .app-kpi__clamp { -webkit-line-clamp: 1; }
.app-kpi__spark { min-inline-size: 80px; margin-block-end: -6px; }
.app-kpi__spark--full { margin-inline: -.25rem; margin-block-end: 0; }
.app-kpi__foot { display: flex; align-items: center; gap: .5rem; min-inline-size: 0; }
.app-kpi--expanded .app-kpi__foot { margin-block-start: auto; }
.app-kpi__link { font-size: .8125rem; font-weight: 600; color: rgb(var(--v-theme-primary)); white-space: nowrap; display: inline-flex; align-items: center; gap: .125rem; }
.app-kpi__breakdown { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: .5rem; }
.app-kpi__breakdown--row { display: flex; gap: 1.25rem; flex: 1 1 auto; justify-content: flex-end; flex-wrap: wrap; }
.app-kpi__stat { display: flex; flex-direction: column; color: inherit; text-decoration: none; min-inline-size: 0; }
.app-kpi__stat--card { padding: .5rem .75rem; border-radius: 10px; background: rgba(var(--v-theme-on-surface), .035); transition: background .15s; }
a.app-kpi__stat--card:hover { background: rgba(var(--v-theme-on-surface), .07); }
.app-kpi__stat-value { font-size: 1.125rem; font-weight: 700; letter-spacing: -.01em; line-height: 1.2; }
.app-kpi__stat-label { font-size: .75rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-kpi__skeleton { display: flex; flex-direction: column; gap: .5rem; }
.app-kpi__sk { border-radius: 8px; background: linear-gradient(90deg, rgba(var(--v-theme-on-surface), .06) 25%, rgba(var(--v-theme-on-surface), .11) 50%, rgba(var(--v-theme-on-surface), .06) 75%); background-size: 200% 100%; animation: app-kpi-shimmer 1.4s infinite linear; }
.app-kpi__sk--value { inline-size: 45%; block-size: 1.75rem; }
.app-kpi__sk--line { inline-size: 70%; block-size: .75rem; }
.app-kpi__error { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
@keyframes app-kpi-shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { .app-kpi__sk { animation: none; } }
</style>
