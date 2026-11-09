<script setup>
// ADR-744/745 — Pointage en direct. Adaptatif : KPI compact dans le strip (densité S
// ou sans données), donut de répartition quand il y a des présences ET de l'espace.
// Données réelles (TimeCockpitReadModel).
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
})

const { t } = useI18n()
const d = computed(() => props.data || {})
const working = computed(() => d.value.working ?? 0)
const onBreak = computed(() => d.value.on_break ?? 0)
const notClocked = computed(() => d.value.not_clocked ?? 0)
const series = computed(() => [working.value, onBreak.value, notClocked.value])
const total = computed(() => series.value.reduce((a, b) => a + b, 0) + (d.value.completed ?? 0))
const hasData = computed(() => total.value > 0)
const showDonut = computed(() => hasData.value && props.viewport?.density !== 'S')
const height = computed(() => (props.viewport?.density === 'M' ? 170 : 200))
const caption = computed(() => hasData.value
  ? t('workforce.widgets.clockCaption', { onBreak: onBreak.value, notClocked: notClocked.value })
  : t('workforce.widgets.clockEmpty'))
</script>

<template>
  <!-- Mode compact (strip) -->
  <KpiShell
    v-if="!showDonut"
    icon="tabler-clock-hour-4" :color="hasData ? 'success' : 'secondary'"
    :label="t('workforce.widgets.clockLabel')"
    :value="working" :caption="caption"
    :loading="loading" :error="d.error"
  />

  <!-- Mode riche (donut) -->
  <div v-else class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('workforce.widgets.clockTitle') }}</div>
      <VChip v-if="(d.anomalies ?? 0) > 0" color="error" size="x-small" variant="tonal" label>
        {{ t('workforce.widgets.clockAnomalies', { n: d.anomalies }) }}
      </VChip>
    </div>
    <div class="flex-grow-1 d-flex align-center justify-center">
      <LzrApexChart
        type="donut" :series="series"
        :labels="[t('workforce.widgets.clockPresent'), t('workforce.widgets.clockOnBreak'), t('workforce.widgets.clockNotClocked')]"
        :colors="['success', 'warning', 'secondary']" :height="height"
      />
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
