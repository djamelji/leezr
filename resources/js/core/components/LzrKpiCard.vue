<script setup>
// Leezr Premium — carte KPI (Phase 0, ADR-741).
// Nombre + delta ▲▼ + sparkline optionnelle + couleur sémantique + icône.
import { computed } from 'vue'
import LzrApexChart from './LzrApexChart.vue'

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  caption: { type: String, default: '' },
  icon: { type: String, default: 'tabler-chart-bar' },
  color: { type: String, default: 'primary' },     // primary|success|warning|error|info
  delta: { type: Number, default: null },           // variation en %
  spark: { type: Array, default: null },            // série sparkline [n,n,...]
  to: { type: [String, Object], default: null },
})

const deltaUp = computed(() => (props.delta ?? 0) >= 0)
const deltaColor = computed(() => (props.delta == null ? 'default' : deltaUp.value ? 'success' : 'error'))
const cardClass = computed(() => `lzr-card lzr-card--raised h-100 ${props.to ? 'cursor-pointer' : ''}`)
</script>

<template>
  <VCard flat :class="cardClass" :to="to">
    <VCardText class="d-flex flex-column h-100 pa-4">
      <div class="d-flex align-center justify-space-between mb-2">
        <VAvatar :color="color" variant="tonal" rounded size="38">
          <VIcon :icon="icon" size="22" />
        </VAvatar>
        <VChip v-if="delta !== null" :color="deltaColor" size="x-small" variant="tonal" label>
          <VIcon :icon="deltaUp ? 'tabler-trending-up' : 'tabler-trending-down'" size="14" start />
          {{ deltaUp ? '+' : '' }}{{ delta }}%
        </VChip>
      </div>
      <div class="lzr-kpi__value text-h4 text-high-emphasis">{{ value }}</div>
      <div class="text-body-2 text-medium-emphasis mt-1">{{ label }}</div>
      <div v-if="caption" class="text-caption text-medium-emphasis">{{ caption }}</div>
      <div v-if="spark && spark.length" class="mt-auto pt-2" style="margin-inline: -8px -8px; margin-block-end: -8px;">
        <LzrApexChart type="area" mode="spark" :height="48" :colors="[color]"
          :series="[{ name: label, data: spark }]" />
      </div>
    </VCardText>
  </VCard>
</template>
