<script setup>
// ADR-746 / ADR-750 (P2.5) — « Répartition par plan » (donut). Données réelles
// (Company.plan_key). Rendu ADAPTATIF par surface (ADR-748b) : le DONUT reste un donut à
// toutes les tailles (représentation compacte adaptée, JAMAIS transformé en sparkline) ;
// seules la hauteur (mesurée) et la densité du chrome varient. Wide = donut + légende côte à côte.
import { computed, ref } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { useMeasuredBlockSize } from '@/core/surface-engine/useMeasuredBlockSize'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})

const PLAN_COLOR = { starter: 'info', pro: 'primary', business: 'warning', enterprise: 'success' }
const dist = computed(() => (d.value.distribution ?? []).filter(x => x.count > 0))
const series = computed(() => dist.value.map(x => x.count))
const labels = computed(() => dist.value.map(x => x.plan.charAt(0).toUpperCase() + x.plan.slice(1)))
const colors = computed(() => dist.value.map(x => PLAN_COLOR[x.plan] || 'secondary'))
const total = computed(() => d.value.total ?? 0)

const root = ref(null)
const chartHost = ref(null)
const { mode, isMicro, isWide } = useSurfacePresentation(root, { viewport: computed(() => props.viewport) })
const chartPx = useMeasuredBlockSize(chartHost)
</script>

<template>
  <div ref="root" class="widget-root d-flex flex-column" :class="[`mode-${mode}`, isMicro ? 'pa-3' : 'pa-5']">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0" :class="isMicro ? 'mb-1' : 'mb-1'">
      <div>
        <div class="font-weight-medium text-high-emphasis text-truncate" :class="isMicro ? 'text-body-2' : 'text-body-1'">{{ t('platform.widgets.planDistribution') }}</div>
        <div v-if="!isMicro" class="text-caption text-medium-emphasis">{{ t('platform.widgets.companiesTotal', { n: total }) }}</div>
      </div>
      <VAvatar v-if="!isMicro && !isWide" color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-layers-subtract" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="image" class="bg-transparent flex-grow-1" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />
    <div v-else-if="!total" class="flex-grow-1 d-flex align-center justify-center text-medium-emphasis text-body-2">{{ t('platform.widgets.noData') }}</div>

    <!-- Donut — hauteur mesurée, jamais coupé. En micro le donut reste lisible (compact). -->
    <div v-else ref="chartHost" class="pd-host" :class="{ 'flex-grow-1': true }">
      <div class="pd-fill d-flex align-center justify-center">
        <LzrApexChart type="donut" :series="series" :labels="labels" :colors="colors" :height="chartPx" class="w-100" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; overflow: hidden; }
.pd-host { position: relative; flex: 1 1 auto; min-block-size: 80px; min-inline-size: 0; overflow: hidden; }
.pd-fill { position: absolute; inset: 0; }
</style>
