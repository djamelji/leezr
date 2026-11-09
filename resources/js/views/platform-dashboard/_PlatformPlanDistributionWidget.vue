<script setup>
// ADR-746 (P2) — « Répartition par plan » (donut). Données réelles (Company.plan_key).
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})

const PLAN_COLOR = { starter: 'info', pro: 'primary', business: 'warning', enterprise: 'success' }
const dist = computed(() => (d.value.distribution ?? []).filter(x => x.count > 0))
const series = computed(() => dist.value.map(x => x.count))
const labels = computed(() => dist.value.map(x => x.plan.charAt(0).toUpperCase() + x.plan.slice(1)))
const colors = computed(() => dist.value.map(x => PLAN_COLOR[x.plan] || 'secondary'))
const total = computed(() => d.value.total ?? 0)
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.planDistribution') }}</div>
        <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.companiesTotal', { n: total }) }}</div>
      </div>
      <VAvatar color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-layers-subtract" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="image" class="bg-transparent flex-grow-1" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />
    <div v-else-if="!total" class="flex-grow-1 d-flex align-center justify-center text-medium-emphasis text-body-2">{{ t('platform.widgets.noData') }}</div>

    <div v-else class="flex-grow-1 d-flex align-center justify-center">
      <LzrApexChart type="donut" :series="series" :labels="labels" :colors="colors" :height="viewport?.density === 'S' ? 150 : 200" class="w-100" />
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
