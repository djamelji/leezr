<script setup>
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  viewport: { type: Object, default: () => ({}) },
})

const d = computed(() => props.data || {})
const labels = computed(() => (Array.isArray(d.value.labels) && d.value.labels.length) ? d.value.labels : ['', '', '', '', '', '', ''])
const hours = computed(() => (Array.isArray(d.value.hours) && d.value.hours.length) ? d.value.hours : [0, 0, 0, 0, 0, 0, 0])
const series = computed(() => [{ name: 'Heures', data: hours.value }])
const totalH = computed(() => hours.value.reduce((a, b) => a + (Number(b) || 0), 0))
const height = computed(() => (props.viewport?.density === 'S' ? 110 : props.viewport?.density === 'M' ? 150 : 180))
</script>

<template>
  <div class="widget-root d-flex flex-column h-100">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div class="d-flex align-center gap-2 overflow-hidden">
        <VAvatar color="primary" variant="tonal" size="34" rounded><VIcon icon="tabler-chart-area-line" size="20" /></VAvatar>
        <span class="text-medium-emphasis text-body-2 text-truncate">Heures travaillées (7 j)</span>
      </div>
      <span class="text-caption text-medium-emphasis flex-shrink-0">{{ Math.round(totalH) }} h au total</span>
    </div>
    <VSkeletonLoader v-if="loading" type="image" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" text="Erreur de chargement" />
    <div v-else :style="{ blockSize: height + 'px' }">
      <LzrApexChart type="bar" :series="series" :categories="labels" :height="height" />
    </div>
  </div>
</template>
