<script setup>
// ADR-745 — panneau composite « Synthèse expéditions ».
// Big KPI + ventilation par statut (donut) + alertes (retard / non assignés). Données réelles.
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const isCompact = computed(() => props.viewport?.density === 'S')

const STATUS_META = {
  planned: { color: 'info', label: 'shipments.status.planned' },
  in_transit: { color: 'primary', label: 'shipments.status.in_transit' },
  delivered: { color: 'success', label: 'shipments.status.delivered' },
  canceled: { color: 'secondary', label: 'shipments.status.canceled' },
  draft: { color: 'secondary', label: 'shipments.status.draft' },
}
// Segments du donut = TOUS les statuts non nuls (brouillon inclus) → la somme du donut
// est strictement égale au « total » affiché (cohérence header ↔ graphe). ADR-745.
const segments = computed(() => {
  const bs = d.value.by_status || {}
  return ['draft', 'planned', 'in_transit', 'delivered', 'canceled'].map(s => ({ s, c: Number(bs[s] || 0) })).filter(x => x.c > 0)
})
const donutSeries = computed(() => segments.value.map(x => x.c))
const donutLabels = computed(() => segments.value.map(x => t(STATUS_META[x.s].label)))
const donutColors = computed(() => segments.value.map(x => STATUS_META[x.s].color))
const total = computed(() => d.value.total ?? 0)
const hasData = computed(() => total.value > 0)
</script>

<template>
  <!-- Compact (densité S / mobile) -->
  <div v-if="isCompact" class="widget-root d-flex flex-column pa-4">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0">
      <span class="text-body-2 text-medium-emphasis text-truncate">{{ t('shipments.widgets.overview') }}</span>
      <VAvatar color="primary" variant="tonal" size="34" rounded><VIcon icon="tabler-truck-delivery" size="18" /></VAvatar>
    </div>
    <div class="text-h4 font-weight-medium text-high-emphasis mt-1">{{ d.today ?? 0 }}</div>
    <div class="text-caption text-medium-emphasis">{{ t('shipments.widgets.todayLabel') }} · {{ t('shipments.widgets.inTransitN', { n: d.in_transit ?? 0 }) }}</div>
  </div>

  <!-- Plein -->
  <div v-else class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('shipments.widgets.overview') }}</div>
        <div class="text-caption text-medium-emphasis">{{ t('shipments.widgets.totalN', { n: total }) }}</div>
      </div>
      <VAvatar color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-truck-delivery" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="image" class="bg-transparent flex-grow-1" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('workforce.widgets.loadError')" />

    <div v-else class="flex-grow-1 d-flex gap-4">
      <!-- Aside : KPI + ventilation + alertes -->
      <div class="ship-aside d-flex flex-column justify-center gap-3 flex-shrink-0">
        <div>
          <div class="text-h4 font-weight-medium text-high-emphasis">{{ d.today ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('shipments.widgets.todayLabel') }}</div>
        </div>
        <VDivider />
        <div class="d-flex flex-column gap-2">
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-truck-loading" color="primary" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('shipments.widgets.inTransitN', { n: d.in_transit ?? 0 }) }}</span>
          </div>
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-clock-exclamation" :color="(d.late ?? 0) > 0 ? 'warning' : 'disabled'" size="18" />
            <span class="text-body-2" :class="(d.late ?? 0) > 0 ? 'text-high-emphasis font-weight-medium' : 'text-medium-emphasis'">{{ t('shipments.widgets.lateN', { n: d.late ?? 0 }) }}</span>
          </div>
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-user-question" :color="(d.unassigned ?? 0) > 0 ? 'warning' : 'disabled'" size="18" />
            <span class="text-body-2" :class="(d.unassigned ?? 0) > 0 ? 'text-high-emphasis font-weight-medium' : 'text-medium-emphasis'">{{ t('shipments.widgets.unassignedN', { n: d.unassigned ?? 0 }) }}</span>
          </div>
        </div>
      </div>

      <!-- Donut répartition par statut -->
      <div class="ship-chart flex-grow-1 d-flex align-center justify-center">
        <LzrApexChart
          v-if="hasData && segments.length"
          class="w-100" type="donut"
          :series="donutSeries" :labels="donutLabels" :colors="donutColors"
          :height="viewport?.density === 'M' ? 180 : 210"
        />
        <div v-else class="text-center">
          <VIcon icon="tabler-package-off" size="30" class="text-disabled mb-2" />
          <div class="text-body-2 text-medium-emphasis">{{ t('shipments.widgets.empty') }}</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; }
.ship-aside { inline-size: 160px; }
@container dashboard-tile (max-width: 540px) {
  .widget-root .d-flex.gap-4 { flex-direction: column; }
  .ship-aside { inline-size: 100%; }
}
</style>
