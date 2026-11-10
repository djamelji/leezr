<script setup>
// ADR-745 / ADR-750 (P2.5) — panneau composite « Synthèse expéditions ». Big KPI +
// ventilation par statut (donut) + alertes. Rendu ADAPTATIF par surface (ADR-748b) :
// micro = KPI ; compact = KPI + donut ; standard/wide/expanded = aside + donut (mesuré,
// jamais coupé). La somme du donut = total affiché (cohérence). Aucun moteur parallèle.
import { computed, ref } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { useMeasuredBlockSize } from '@/core/surface-engine/useMeasuredBlockSize'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})

const STATUS_META = {
  planned: { color: 'info', label: 'shipments.status.planned' },
  in_transit: { color: 'primary', label: 'shipments.status.in_transit' },
  delivered: { color: 'success', label: 'shipments.status.delivered' },
  canceled: { color: 'secondary', label: 'shipments.status.canceled' },
  draft: { color: 'secondary', label: 'shipments.status.draft' },
}
const segments = computed(() => {
  const bs = d.value.by_status || {}

  return ['draft', 'planned', 'in_transit', 'delivered', 'canceled'].map(s => ({ s, c: Number(bs[s] || 0) })).filter(x => x.c > 0)
})
const donutSeries = computed(() => segments.value.map(x => x.c))
const donutLabels = computed(() => segments.value.map(x => t(STATUS_META[x.s].label)))
const donutColors = computed(() => segments.value.map(x => STATUS_META[x.s].color))
const total = computed(() => d.value.total ?? 0)
const hasData = computed(() => total.value > 0 && segments.value.length > 0)

const root = ref(null)
const chartHost = ref(null)
const { mode, isMicro, isCompact } = useSurfacePresentation(root, { viewport: computed(() => props.viewport) })
const chartPx = useMeasuredBlockSize(chartHost)
const showAside = computed(() => !isMicro.value && !isCompact.value)
</script>

<template>
  <div ref="root" class="widget-root d-flex flex-column" :class="[`mode-${mode}`, isMicro ? 'pa-4' : 'pa-5']">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0" :class="isMicro ? '' : 'mb-1'">
      <div>
        <div class="font-weight-medium text-high-emphasis text-truncate" :class="isMicro ? 'text-body-2' : 'text-body-1'">{{ t('shipments.widgets.overview') }}</div>
        <div v-if="!isMicro" class="text-caption text-medium-emphasis">{{ t('shipments.widgets.totalN', { n: total }) }}</div>
      </div>
      <VAvatar color="primary" variant="tonal" :size="isMicro ? 34 : 40" rounded><VIcon icon="tabler-truck-delivery" :size="isMicro ? 18 : 22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading && !isMicro" type="image" class="bg-transparent flex-grow-1" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('workforce.widgets.loadError')" />

    <!-- MICRO : KPI principal -->
    <template v-else-if="isMicro">
      <div class="text-h4 font-weight-medium text-high-emphasis mt-1">{{ d.today ?? 0 }}</div>
      <div class="text-caption text-medium-emphasis text-truncate">{{ t('shipments.widgets.todayLabel') }} · {{ t('shipments.widgets.inTransitN', { n: d.in_transit ?? 0 }) }}</div>
    </template>

    <!-- COMPACT / STANDARD / WIDE / EXPANDED : (aside) + donut -->
    <div v-else class="flex-grow-1 d-flex gap-4" style="min-block-size: 0">
      <div v-if="showAside" class="ship-aside d-flex flex-column justify-center gap-3 flex-shrink-0">
        <div>
          <div class="text-h4 font-weight-medium text-high-emphasis">{{ d.today ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('shipments.widgets.todayLabel') }}</div>
        </div>
        <VDivider />
        <div class="d-flex flex-column gap-2">
          <div class="d-flex align-center gap-2"><VIcon icon="tabler-truck-loading" color="primary" size="18" /><span class="text-body-2 text-medium-emphasis">{{ t('shipments.widgets.inTransitN', { n: d.in_transit ?? 0 }) }}</span></div>
          <div class="d-flex align-center gap-2"><VIcon icon="tabler-clock-exclamation" :color="(d.late ?? 0) > 0 ? 'warning' : 'disabled'" size="18" /><span class="text-body-2" :class="(d.late ?? 0) > 0 ? 'text-high-emphasis font-weight-medium' : 'text-medium-emphasis'">{{ t('shipments.widgets.lateN', { n: d.late ?? 0 }) }}</span></div>
          <div class="d-flex align-center gap-2"><VIcon icon="tabler-user-question" :color="(d.unassigned ?? 0) > 0 ? 'warning' : 'disabled'" size="18" /><span class="text-body-2" :class="(d.unassigned ?? 0) > 0 ? 'text-high-emphasis font-weight-medium' : 'text-medium-emphasis'">{{ t('shipments.widgets.unassignedN', { n: d.unassigned ?? 0 }) }}</span></div>
        </div>
      </div>

      <div ref="chartHost" class="ship-host flex-grow-1">
        <div v-if="hasData" class="ship-fill d-flex align-center justify-center">
          <LzrApexChart class="w-100" type="donut" :series="donutSeries" :labels="donutLabels" :colors="donutColors" :height="chartPx" />
        </div>
        <div v-else class="ship-empty text-center">
          <VIcon icon="tabler-package-off" size="30" class="text-disabled mb-2" />
          <div class="text-body-2 text-medium-emphasis">{{ t('shipments.widgets.empty') }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; overflow: hidden; }
.ship-aside { inline-size: 160px; }
.ship-host { position: relative; min-block-size: 80px; min-inline-size: 0; overflow: hidden; }
.ship-fill, .ship-empty { position: absolute; inset: 0; }
.ship-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; }
</style>
