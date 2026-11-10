<script setup>
// ADR-744/745 / ADR-750 (P2.5) — Pointage en direct. Rendu ADAPTATIF par surface
// (ADR-748b) : micro (ou aucune donnée) = KPI compact (KpiShell) ; dès qu'il y a de la
// présence ET de l'espace = donut de répartition (hauteur mesurée, jamais coupé).
// Données réelles (TimeCockpitReadModel). Aucun moteur parallèle.
import { computed, ref } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import KpiShell from './_KpiShell.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { useMeasuredBlockSize } from '@/core/surface-engine/useMeasuredBlockSize'

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

const root = ref(null)
const chartHost = ref(null)
const { mode, isMicro } = useSurfacePresentation(root, { viewport: computed(() => props.viewport) })
const chartPx = useMeasuredBlockSize(chartHost)
// Donut dès qu'il y a de la présence ET qu'on n'est pas en micro (sinon KPI compact).
const showDonut = computed(() => hasData.value && !isMicro.value)
const caption = computed(() => hasData.value
  ? t('workforce.widgets.clockCaption', { onBreak: onBreak.value, notClocked: notClocked.value })
  : t('workforce.widgets.clockEmpty'))
</script>

<template>
  <!-- Mesure toujours montée pour que `mode` se résolve même en rendu KPI -->
  <div ref="root" class="widget-root d-flex flex-column" :class="showDonut ? 'pa-5' : ''">
    <!-- Mode compact (KPI) -->
    <KpiShell
      v-if="!showDonut"
      icon="tabler-clock-hour-4" :color="hasData ? 'success' : 'secondary'"
      :label="t('workforce.widgets.clockLabel')"
      :value="working" :caption="caption"
      :loading="loading" :error="d.error"
    />

    <!-- Mode riche (donut) -->
    <template v-else>
      <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
        <div class="text-body-1 font-weight-medium text-high-emphasis text-truncate">{{ t('workforce.widgets.clockTitle') }}</div>
        <VChip v-if="(d.anomalies ?? 0) > 0" color="error" size="x-small" variant="tonal" label>
          {{ t('workforce.widgets.clockAnomalies', { n: d.anomalies }) }}
        </VChip>
      </div>
      <div ref="chartHost" class="clk-host flex-grow-1">
        <div class="clk-fill d-flex align-center justify-center">
          <LzrApexChart
            type="donut" :series="series"
            :labels="[t('workforce.widgets.clockPresent'), t('workforce.widgets.clockOnBreak'), t('workforce.widgets.clockNotClocked')]"
            :colors="['success', 'warning', 'secondary']" :height="chartPx" class="w-100"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; overflow: hidden; }
.clk-host { position: relative; min-block-size: 80px; min-inline-size: 0; overflow: hidden; }
.clk-fill { position: absolute; inset: 0; }
</style>
