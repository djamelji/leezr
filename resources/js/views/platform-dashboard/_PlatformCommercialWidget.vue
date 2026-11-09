<script setup>
// ADR-747/748 (P2) — hero « Pilotage commercial » : grand graphique immersif MRR (niveau),
// période (Semaine/Mois/Année) + navigation + comparatif N-1, RENDU ADAPTATIF par surface
// réelle (largeur ET hauteur) via le Presentation Engine partagé (resolvePresentationMode).
import { computed, ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useTheme } from 'vuetify'
import { hexToRgb } from '@layouts/utils'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { $platformApi } from '@/utils/platformApi'
import { resolvePresentationMode } from '@/core/surface-engine/presentationMode'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const theme = useTheme()

// ── Auto-mesure (racine → mode). Les options du graphe sont dérivées du MODE (bucketé,
// stable), donc un resize ne fait varier que :width/:height (resize natif ApexCharts) :
// la mesure peut donc être immédiate sans provoquer de churn d'options ni de race.
const root = ref(null)
const rootW = ref(0); const rootH = ref(0)
let rootRo = null
onMounted(() => {
  if (!root.value) return
  rootRo = new ResizeObserver(es => { const r = es[0]?.contentRect; if (r) { rootW.value = Math.round(r.width); rootH.value = Math.round(r.height) } })
  rootRo.observe(root.value)
})
onBeforeUnmount(() => { if (rootRo) rootRo.disconnect() })
const mode = computed(() => (rootW.value > 0 && rootH.value > 0) ? resolvePresentationMode(rootW.value, rootH.value) : (props.viewport?.mode || 'standard'))
const isMicro = computed(() => mode.value === 'micro')
const isCompact = computed(() => mode.value === 'compact')
const isWide = computed(() => mode.value === 'wide')
const isExpanded = computed(() => mode.value === 'expanded')
// Disposition des stats : aside (expanded) | strip (wide/standard) | min (compact)
const statsLayout = computed(() => isExpanded.value ? 'aside' : isCompact.value ? 'min' : 'strip')
// Contrôles : menu déroulant si compact, segmenté sinon
const selectorKind = computed(() => isCompact.value ? 'select' : 'segmented')

// ── État interactif ──
const d = computed(() => props.data || {})
const series = ref(d.value.series || null)
// KPI : props.data (batch) prioritaire, sinon ceux de la série (widget autosuffisant).
const k = computed(() => series.value?.kpis || {})
const mrr = computed(() => d.value.mrr ?? k.value.mrr ?? 0)
const arr = computed(() => d.value.arr ?? k.value.arr ?? 0)
const activeSubs = computed(() => d.value.active_subscriptions ?? k.value.active_subscriptions ?? 0)
const trials = computed(() => d.value.trialing_subscriptions ?? k.value.trialing_subscriptions ?? 0)
onMounted(() => { if (!series.value) fetchSeries() })
const period = ref(series.value?.period || 'month')
const anchor = ref(null)
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
  } catch (e) { fetchError.value = true }
  finally { fetching.value = false }
}
function setPeriod(p) { if (!p || p === period.value) return; period.value = p; anchor.value = null; fetchSeries() }
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

// ── Hauteur du graphe DÉRIVÉE de la hauteur de racine mesurée (rootH, fiable — elle pilote
// déjà le mode) moins les offsets connus (en-tête + bandeau stats par mode). LzrApexChart
// gère sa largeur. Aucune mesure flaky du host → rendu stable à toutes les tailles.
const chartPx = computed(() => {
  const h = rootH.value
  if (h < 40) return 0
  const header = isCompact.value ? 46 : isWide.value ? 44 : 56
  const strip = isExpanded.value ? 0 : isWide.value ? 32 : isCompact.value ? 38 : 40
  return Math.max(60, Math.round(h - header - strip))
})
const ready = computed(() => chartPx.value > 40)

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

const periods = [
  { key: 'week', label: 'platform.widgets.commercial.week' },
  { key: 'month', label: 'platform.widgets.commercial.month' },
  { key: 'year', label: 'platform.widgets.commercial.year' },
]
const periodItems = computed(() => periods.map(p => ({ title: t(p.label), value: p.key })))
const summary = computed(() => series.value?.summary || {})
const deltaPct = computed(() => summary.value.delta_pct)

// KPIs normalisés (évite les libellés tronqués : on réorganise selon le mode)
const kpis = computed(() => [
  { key: 'arr', i: 'tabler-businessplan', c: 'success', l: t('platform.widgets.commercial.arr'), val: money(arr.value) },
  { key: 'subs', i: 'tabler-repeat', c: 'primary', l: t('platform.widgets.activeSubsShort'), val: activeSubs.value },
  { key: 'trials', i: 'tabler-hourglass', c: 'info', l: t('platform.widgets.commercial.trials'), val: trials.value },
])
</script>

<template>
  <div ref="root" class="widget-root commercial-widget d-flex flex-column" :class="`mode-${mode}`">
    <!-- ══ MICRO : KPI principal + sparkline + accès détail ══ -->
    <template v-if="isMicro">
      <div class="cw-micro d-flex flex-column pa-3">
        <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0">
          <span class="text-caption text-medium-emphasis text-truncate">{{ t('platform.widgets.commercialOverview') }}</span>
          <VMenu>
            <template #activator="{ props: mp }"><VBtn v-bind="mp" icon variant="text" size="x-small" :aria-label="t('platform.widgets.commercial.month')"><VIcon icon="tabler-calendar" size="16" /></VBtn></template>
            <VList density="compact"><VListItem v-for="p in periodItems" :key="p.value" :title="p.title" @click="setPeriod(p.value)" /></VList>
          </VMenu>
        </div>
        <div class="d-flex align-baseline gap-2">
          <span class="text-h6 font-weight-medium text-high-emphasis">{{ money(mrr) }}</span>
          <span v-if="showPrev && deltaPct !== null" class="text-caption font-weight-medium" :class="deltaPct >= 0 ? 'text-success' : 'text-error'">{{ pct(deltaPct) }}</span>
        </div>
        <div class="flex-grow-1 d-flex align-end">
          <LzrApexChart v-if="hasData" class="w-100" type="area" mode="spark" :series="[{ name: 'MRR', data: curData }]" :height="36" />
        </div>
      </div>
    </template>

    <!-- ══ EXPANDED / WIDE / STANDARD / COMPACT ══ -->
    <template v-else>
      <!-- En-tête adaptatif (sans chevauchement) -->
      <div class="cw-head d-flex align-center flex-wrap gap-x-3 gap-y-2" :class="isCompact ? 'px-3 pt-3' : 'px-5 pt-4'">
        <div class="cw-title me-auto">
          <div class="font-weight-medium text-high-emphasis text-truncate" :class="isCompact ? 'text-body-2' : 'text-body-1'">{{ t('platform.widgets.commercialOverview') }}</div>
          <div v-if="!isWide" class="text-caption text-medium-emphasis text-truncate">{{ series?.label || t('platform.widgets.commercialSub') }}<span v-if="series?.partial"> · {{ t('platform.widgets.commercial.partial') }}</span></div>
        </div>

        <!-- Sélecteur de période : segmenté OU menu déroulant (compact) -->
        <VBtnToggle v-if="selectorKind === 'segmented'" :model-value="period" density="compact" variant="outlined" divided mandatory class="cw-periods flex-shrink-0" @update:model-value="setPeriod">
          <VBtn v-for="p in periods" :key="p.key" :value="p.key" size="small">{{ t(p.label) }}</VBtn>
        </VBtnToggle>
        <VSelect v-else :model-value="period" :items="periodItems" density="compact" variant="outlined" hide-details class="cw-select flex-shrink-0" @update:model-value="setPeriod" />

        <div class="d-flex align-center flex-shrink-0">
          <VBtn icon variant="text" size="x-small" :aria-label="t('platform.widgets.commercial.prev')" @click="nav(-1)"><VIcon icon="tabler-chevron-left" size="18" /></VBtn>
          <VBtn v-if="!isCompact" variant="text" size="x-small" :disabled="!anchor" @click="today">{{ t('platform.widgets.commercial.today') }}</VBtn>
          <VBtn v-else icon variant="text" size="x-small" :disabled="!anchor" :aria-label="t('platform.widgets.commercial.today')" @click="today"><VIcon icon="tabler-target" size="16" /></VBtn>
          <VBtn icon variant="text" size="x-small" :disabled="!series?.can_next" :aria-label="t('platform.widgets.commercial.next')" @click="nav(1)"><VIcon icon="tabler-chevron-right" size="18" /></VBtn>
        </div>

        <VBtn v-if="!isCompact" :variant="compare ? 'tonal' : 'text'" :color="compare ? 'primary' : undefined" size="small" prepend-icon="tabler-git-compare" class="flex-shrink-0" @click="toggleCompare">{{ t('platform.widgets.commercial.compareN1') }}</VBtn>
        <VBtn v-else icon :variant="compare ? 'tonal' : 'text'" :color="compare ? 'primary' : undefined" size="x-small" :aria-label="t('platform.widgets.commercial.compareN1')" class="flex-shrink-0" @click="toggleCompare"><VIcon icon="tabler-git-compare" size="16" /></VBtn>
      </div>

      <!-- Corps : aside (expanded) | bandeau (wide/standard) | minimal (compact) -->
      <div class="cw-body d-flex flex-grow-1" :class="statsLayout === 'aside' ? 'flex-row' : 'flex-column'">
        <!-- Bandeau KPI AU-DESSUS en mode wide (peu haut → stats compactes, graphe dominant) -->
        <div v-if="statsLayout === 'strip' && isWide" class="cw-strip d-flex align-center gap-4 flex-wrap px-5 py-1 flex-shrink-0">
          <div class="d-flex align-baseline gap-1"><span class="text-subtitle-1 font-weight-medium text-high-emphasis">{{ money(mrr) }}</span><span class="text-caption text-medium-emphasis">MRR</span><span v-if="showPrev && deltaPct !== null" class="text-caption font-weight-medium ms-1" :class="deltaPct >= 0 ? 'text-success' : 'text-error'">{{ pct(deltaPct) }}</span></div>
          <div v-for="k in kpis" :key="k.key" class="d-flex align-baseline gap-1"><span class="text-body-2 font-weight-medium text-high-emphasis">{{ k.val }}</span><span class="text-caption text-medium-emphasis">{{ k.l }}</span></div>
        </div>

        <!-- Zone graphique (remplit la surface restante) -->
        <div class="cw-chart">
          <div ref="chartHost" class="cw-chart-host">
            <VProgressCircular v-if="(loading || fetching) && !series" indeterminate size="26" class="cw-center" />
            <div v-else-if="fetchError || series?.error" class="cw-center text-center"><VIcon icon="tabler-alert-circle" color="error" size="24" class="mb-1" /><div class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.loadError') }}</div></div>
            <div v-else-if="!hasData" class="cw-center text-center"><VIcon icon="tabler-chart-line" size="26" class="text-disabled mb-2" /><div class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.commercial.noData') }}</div></div>
            <!-- Graphe (LzrApexChart, wrapper éprouvé) : double-série N-1 en standard/wide/expanded,
                 série unique en compact. Rendu fiable quelle que soit la taille. -->
            <LzrApexChart v-else-if="ready" class="w-100" type="area"
              :series="isCompact ? [{ name: 'MRR', data: curData }] : apexSeries"
              :categories="labels" :height="chartPx" :colors="['primary']"
              :compare="!isCompact && showPrev" :format-y="v => money(v * 100)" />
            <div v-if="compare && !series?.has_history && hasData" class="cw-note text-caption text-medium-emphasis"><VIcon icon="tabler-info-circle" size="14" /> {{ t('platform.widgets.commercial.noHistoryN1') }}</div>
          </div>
        </div>

        <!-- Stats : ASIDE (expanded) -->
        <div v-if="statsLayout === 'aside'" class="cw-aside flex-shrink-0 d-flex flex-column gap-3 px-5 py-4">
          <div>
            <div class="d-flex align-baseline gap-2"><span class="text-h5 font-weight-medium text-high-emphasis">{{ money(mrr) }}</span><span v-if="showPrev && deltaPct !== null" class="text-caption font-weight-medium" :class="deltaPct >= 0 ? 'text-success' : 'text-error'">{{ pct(deltaPct) }}</span></div>
            <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.commercial.mrrNow') }}</div>
          </div>
          <VDivider />
          <div class="d-flex flex-column gap-3">
            <div v-for="k in kpis" :key="k.key" class="d-flex align-center gap-2">
              <VAvatar :color="k.c" variant="tonal" size="30" rounded><VIcon :icon="k.i" size="16" /></VAvatar>
              <div class="overflow-hidden"><div class="text-body-2 font-weight-medium text-high-emphasis text-truncate">{{ k.val }}</div><div class="text-caption text-medium-emphasis text-truncate">{{ k.l }}</div></div>
            </div>
          </div>
          <div v-if="showPrev && summary.mrr_prev_equiv !== null" class="mt-auto"><VDivider class="mb-2" /><div class="text-caption text-medium-emphasis">{{ t('platform.widgets.commercial.vsN1') }}</div><div class="text-body-2 text-high-emphasis">{{ money(summary.mrr_prev_equiv) }}</div></div>
        </div>

        <!-- Stats : BANDEAU sous le graphe (standard) -->
        <div v-else-if="statsLayout === 'strip' && !isWide" class="cw-strip d-flex align-center gap-4 flex-wrap px-5 py-2 flex-shrink-0">
          <div class="d-flex align-baseline gap-1"><span class="text-subtitle-1 font-weight-medium text-high-emphasis">{{ money(mrr) }}</span><span class="text-caption text-medium-emphasis">MRR</span><span v-if="showPrev && deltaPct !== null" class="text-caption font-weight-medium ms-1" :class="deltaPct >= 0 ? 'text-success' : 'text-error'">{{ pct(deltaPct) }}</span></div>
          <div v-for="k in kpis" :key="k.key" class="d-flex align-baseline gap-1"><span class="text-body-2 font-weight-medium text-high-emphasis">{{ k.val }}</span><span class="text-caption text-medium-emphasis">{{ k.l }}</span></div>
        </div>

        <!-- Stats : MINIMAL (compact) -->
        <div v-else-if="statsLayout === 'min'" class="cw-min d-flex align-center gap-3 px-3 py-2 flex-shrink-0">
          <div class="d-flex align-baseline gap-1"><span class="text-subtitle-1 font-weight-medium text-high-emphasis">{{ money(mrr) }}</span><span class="text-caption text-medium-emphasis">MRR</span></div>
          <div class="d-flex align-baseline gap-1"><span class="text-body-2 font-weight-medium text-high-emphasis">{{ money(arr) }}</span><span class="text-caption text-medium-emphasis">ARR</span></div>
          <span v-if="showPrev && deltaPct !== null" class="text-caption font-weight-medium ms-auto" :class="deltaPct >= 0 ? 'text-success' : 'text-error'">{{ pct(deltaPct) }}</span>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; }
.widget-root { overflow: hidden; }
.cw-head { row-gap: 8px; min-inline-size: 0; }
.cw-head > * { min-inline-size: 0; }
.cw-title { min-inline-size: 0; }
.cw-periods :deep(.v-btn) { text-transform: none; }
.cw-select { max-inline-size: 130px; }
.cw-body { min-block-size: 0; min-inline-size: 0; }
.cw-chart { flex: 1 1 auto; min-inline-size: 0; min-block-size: 0; display: flex; flex-direction: column; overflow: hidden; }
.cw-chart-host { position: relative; flex: 1 1 auto; min-block-size: 80px; min-inline-size: 0; padding-inline: 6px; overflow: hidden; }
.cw-chart-host :deep(.apexcharts-canvas) { max-inline-size: 100%; }
.cw-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.cw-note { position: absolute; inset-inline: 10px; inset-block-end: 0; pointer-events: none; }
.cw-aside { inline-size: 200px; border-inline-start: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.cw-strip { border-block-start: 1px solid rgba(var(--v-theme-on-surface), 0.08); row-gap: 2px; }
.mode-wide .cw-strip { border-block-start: none; border-block-end: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.cw-min { border-block-start: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.cw-micro { block-size: 100%; }
</style>
