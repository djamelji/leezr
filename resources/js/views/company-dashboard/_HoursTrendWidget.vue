<script setup>
// ADR-745 — panneau hero « Heures travaillées · 7 j ».
// Composite barres (jour pic en relief) + résumé latéral. Pattern Vuexy
// AnalyticsEarningReportsWeeklyOverview appliqué aux données réelles (labels/hours).
import { computed } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  viewport: { type: Object, default: () => ({}) },
})

const d = computed(() => props.data || {})
const labels = computed(() => (Array.isArray(d.value.labels) && d.value.labels.length) ? d.value.labels : ['', '', '', '', '', '', ''])
const hours = computed(() => (Array.isArray(d.value.hours) && d.value.hours.length) ? d.value.hours.map(h => Number(h) || 0) : [0, 0, 0, 0, 0, 0, 0])
const series = computed(() => [{ name: 'Heures', data: hours.value }])

const total = computed(() => hours.value.reduce((a, b) => a + b, 0))
const peakIndex = computed(() => hours.value.reduce((best, v, i) => v > hours.value[best] ? i : best, 0))
const peakVal = computed(() => hours.value[peakIndex.value] || 0)
const activeDays = computed(() => hours.value.filter(v => v > 0).length)
const avgPerActive = computed(() => activeDays.value ? total.value / activeDays.value : 0)
const hasData = computed(() => total.value > 0)

const fmt = n => Number.isInteger(n) ? String(n) : n.toFixed(1)
const formatY = v => `${fmt(v)} h`

// Densité S (mobile, grille forcée à h=2) → mode compact : total + sparkline (le composite
// complet ne tient pas dans 160px). ADR-745.
const isCompact = computed(() => props.viewport?.density === 'S')
</script>

<template>
  <!-- Mode compact (densité S / mobile) -->
  <div v-if="isCompact" class="widget-root d-flex flex-column pa-4">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0">
      <span class="text-body-2 text-medium-emphasis text-truncate">{{ $t('workforce.widgets.hoursPanelTitle') }}</span>
      <VAvatar color="primary" variant="tonal" size="34" rounded><VIcon icon="tabler-clock-hour-8" size="18" /></VAvatar>
    </div>
    <div class="d-flex align-baseline gap-1 mt-1">
      <span class="text-h4 font-weight-medium text-high-emphasis">{{ fmt(total) }}</span>
      <span class="text-body-2 text-medium-emphasis">h · {{ $t('workforce.widgets.hoursPanelSub') }}</span>
    </div>
    <div class="flex-grow-1 d-flex align-end">
      <LzrApexChart v-if="hasData" class="w-100" type="bar" mode="spark" :series="series" :categories="labels" :highlight-index="peakIndex" :height="46" />
      <span v-else class="text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursEmpty') }}</span>
    </div>
  </div>

  <!-- Mode plein (desktop/tablette) -->
  <div v-else class="widget-root hours-panel d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-1 flex-shrink-0">
      <div>
        <div class="text-body-1 font-weight-medium text-high-emphasis">{{ $t('workforce.widgets.hoursPanelTitle') }}</div>
        <div class="text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursPanelSub') }}</div>
      </div>
      <VAvatar color="primary" variant="tonal" size="40" rounded>
        <VIcon icon="tabler-clock-hour-8" size="22" />
      </VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="image" class="bg-transparent flex-grow-1" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="$t('workforce.widgets.loadError')" />

    <div v-else class="hours-body flex-grow-1 d-flex gap-4">
      <!-- Résumé latéral -->
      <div class="hours-aside d-flex flex-column justify-center gap-4 flex-shrink-0">
        <div>
          <div class="text-h4 font-weight-medium text-high-emphasis">{{ fmt(total) }}<span class="text-body-1 text-medium-emphasis"> h</span></div>
          <div class="text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursTotal') }}</div>
        </div>
        <VDivider />
        <div class="d-flex flex-column gap-3">
          <div class="d-flex align-center gap-2">
            <VAvatar size="30" variant="tonal" color="success" rounded><VIcon icon="tabler-chart-bar" size="16" /></VAvatar>
            <div>
              <div class="text-body-2 font-weight-medium text-high-emphasis">{{ hasData ? fmt(avgPerActive) + ' h' : '—' }}</div>
              <div class="text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursAvg') }}</div>
            </div>
          </div>
          <div class="d-flex align-center gap-2">
            <VAvatar size="30" variant="tonal" color="primary" rounded><VIcon icon="tabler-flame" size="16" /></VAvatar>
            <div>
              <div class="text-body-2 font-weight-medium text-high-emphasis">{{ hasData ? labels[peakIndex] + ' · ' + fmt(peakVal) + ' h' : '—' }}</div>
              <div class="text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursPeak') }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Graphe -->
      <div class="hours-chart flex-grow-1 d-flex align-center">
        <div v-if="!hasData" class="w-100 text-center py-4">
          <VIcon icon="tabler-chart-bar-off" size="30" class="text-disabled mb-2" />
          <div class="text-body-2 text-medium-emphasis">{{ $t('workforce.widgets.hoursEmpty') }}</div>
        </div>
        <LzrApexChart
          v-else
          class="w-100"
          type="bar"
          :series="series"
          :categories="labels"
          :highlight-index="peakIndex"
          :format-y="formatY"
          :height="viewport?.density === 'S' ? 130 : 250"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; }
.hours-aside { inline-size: 150px; }
@container dashboard-tile (max-width: 560px) {
  .hours-body { flex-direction: column; }
  .hours-aside { inline-size: 100%; }
}
</style>
