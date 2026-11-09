<script setup>
// ADR-746 (P2) — « Companies & inscriptions » (platform). Données réelles (Company).
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const trend = computed(() => d.value.trend?.values ?? [])
const hasTrend = computed(() => trend.value.some(v => v > 0))
const series = computed(() => [{ name: 'Inscriptions', data: trend.value }])
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-2 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platform.widgets.companiesOverview') }}</div>
      <VAvatar color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-building-community" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />

    <div v-else class="flex-grow-1 d-flex gap-5">
      <div class="d-flex flex-column justify-center gap-3 flex-shrink-0" style="inline-size: 150px">
        <div>
          <div class="text-h4 font-weight-medium text-high-emphasis">{{ d.active ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.companiesActive') }}</div>
        </div>
        <VDivider />
        <div class="d-flex flex-column gap-2">
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-user-plus" color="success" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.new30d', { n: d.new_30d ?? 0 }) }}</span>
          </div>
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-player-pause" :color="(d.suspended ?? 0) > 0 ? 'warning' : 'disabled'" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.suspendedN', { n: d.suspended ?? 0 }) }}</span>
          </div>
        </div>
      </div>
      <div class="flex-grow-1 d-flex align-center">
        <LzrApexChart v-if="hasTrend" class="w-100" type="bar" :series="series" :categories="d.trend?.labels ?? []" :height="viewport?.density === 'S' ? 90 : 140" />
        <div v-else class="w-100 text-center text-caption text-medium-emphasis">{{ t('platform.widgets.noNewThisWeek') }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
