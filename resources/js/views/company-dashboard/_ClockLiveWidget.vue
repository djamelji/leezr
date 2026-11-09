<script setup>
// ADR-744 (P1) — Pointage en direct (donut). Server-resolved : reçoit `data`
// du batch dashboard. Données réelles (TimeCockpitReadModel). Drill-down → cockpit temps.
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
})

const d = computed(() => props.data || {})
const series = computed(() => [d.value.working ?? 0, d.value.on_break ?? 0, d.value.not_clocked ?? 0])
const total = computed(() => series.value.reduce((a, b) => a + b, 0) + (d.value.completed ?? 0))
const hasData = computed(() => total.value > 0)
const height = computed(() => (props.viewport?.density === 'S' ? 140 : props.viewport?.density === 'M' ? 170 : 200))
</script>

<template>
  <div class="widget-root d-flex flex-column h-100">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div class="d-flex align-center gap-2 overflow-hidden">
        <VAvatar color="primary" variant="tonal" size="34" rounded>
          <VIcon icon="tabler-clock-hour-4" size="20" />
        </VAvatar>
        <span class="text-medium-emphasis text-body-2 text-truncate">Pointage en direct</span>
      </div>
      <VChip v-if="hasData && (d.anomalies ?? 0) > 0" color="error" size="x-small" variant="tonal" label>
        {{ d.anomalies }} anomalie{{ d.anomalies > 1 ? 's' : '' }}
      </VChip>
    </div>

    <div class="flex-grow-1 d-flex align-center justify-center">
      <VSkeletonLoader v-if="loading" type="image" width="150" class="bg-transparent" />
      <div v-else-if="!hasData" class="text-medium-emphasis text-body-2 text-center">Aucun pointage aujourd'hui</div>
      <LzrApexChart
        v-else
        type="donut"
        :series="series"
        :labels="['Présents', 'En pause', 'Non pointés']"
        :colors="['success', 'warning', 'secondary']"
        :height="height"
      />
    </div>
  </div>
</template>
