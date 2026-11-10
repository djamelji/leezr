<script setup>
// ADR-745 / ADR-750 (P2.5) — hero « Heures travaillées · 7 j ». Composite barres (jour pic
// en relief) + résumé. Rendu ADAPTATIF par surface via le Presentation Engine partagé
// (ADR-748b) : micro = total + sparkline ; compact = KPI + barres ; wide = barres
// panoramiques + KPI en bandeau ; standard/expanded = aside + barres. Aucun moteur parallèle.
import { computed, ref } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { useMeasuredBlockSize } from '@/core/surface-engine/useMeasuredBlockSize'

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

const root = ref(null)
const chartHost = ref(null)
const { mode, isMicro, isCompact, isWide } = useSurfacePresentation(root, { viewport: computed(() => props.viewport) })
const chartPx = useMeasuredBlockSize(chartHost)
</script>

<template>
  <div ref="root" class="widget-root hours-panel d-flex flex-column" :class="[`mode-${mode}`, isMicro ? 'pa-4' : 'pa-5']">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0" :class="isMicro ? '' : 'mb-1'">
      <div>
        <div class="font-weight-medium text-high-emphasis text-truncate" :class="isMicro ? 'text-body-2' : 'text-body-1'">{{ $t('workforce.widgets.hoursPanelTitle') }}</div>
        <div v-if="!isMicro" class="text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursPanelSub') }}</div>
      </div>
      <VAvatar v-if="!isWide" color="primary" variant="tonal" :size="isMicro ? 34 : 40" rounded><VIcon icon="tabler-clock-hour-8" :size="isMicro ? 18 : 22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading && !isMicro" type="image" class="bg-transparent flex-grow-1" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="$t('workforce.widgets.loadError')" />

    <!-- MICRO : total + sparkline -->
    <template v-else-if="isMicro">
      <div class="d-flex align-baseline gap-1 mt-1">
        <span class="text-h5 font-weight-medium text-high-emphasis">{{ fmt(total) }}</span>
        <span class="text-caption text-medium-emphasis text-truncate">h · {{ $t('workforce.widgets.hoursPanelSub') }}</span>
      </div>
      <div ref="chartHost" class="ht-host">
        <div v-if="hasData" class="ht-fill"><LzrApexChart class="w-100" type="bar" mode="spark" :series="series" :categories="labels" :highlight-index="peakIndex" :height="chartPx" /></div>
        <span v-else class="ht-empty text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursEmpty') }}</span>
      </div>
    </template>

    <!-- COMPACT : KPI en ligne + barres -->
    <template v-else-if="isCompact">
      <div class="d-flex align-baseline gap-3 flex-wrap mt-1 flex-shrink-0">
        <span class="d-flex align-baseline gap-1"><span class="text-h6 font-weight-medium text-high-emphasis">{{ fmt(total) }}</span><span class="text-caption text-medium-emphasis">h · {{ $t('workforce.widgets.hoursTotal') }}</span></span>
        <span v-if="hasData" class="text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursAvg') }} {{ fmt(avgPerActive) }} h</span>
      </div>
      <div ref="chartHost" class="ht-host">
        <div v-if="hasData" class="ht-fill"><LzrApexChart class="w-100" type="bar" :series="series" :categories="labels" :highlight-index="peakIndex" :format-y="formatY" :height="chartPx" /></div>
        <span v-else class="ht-empty text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursEmpty') }}</span>
      </div>
    </template>

    <!-- WIDE : bandeau KPI + barres panoramiques -->
    <template v-else-if="isWide">
      <div class="d-flex align-center gap-5 flex-wrap mt-1 flex-shrink-0">
        <div class="d-flex align-baseline gap-1"><span class="text-h5 font-weight-medium text-high-emphasis">{{ fmt(total) }}</span><span class="text-caption text-medium-emphasis">h · {{ $t('workforce.widgets.hoursTotal') }}</span></div>
        <div v-if="hasData" class="text-body-2 text-medium-emphasis">{{ $t('workforce.widgets.hoursAvg') }} {{ fmt(avgPerActive) }} h</div>
        <div v-if="hasData" class="text-body-2 text-medium-emphasis">{{ $t('workforce.widgets.hoursPeak') }} · {{ labels[peakIndex] }} {{ fmt(peakVal) }} h</div>
      </div>
      <div ref="chartHost" class="ht-host">
        <div v-if="hasData" class="ht-fill"><LzrApexChart class="w-100" type="bar" :series="series" :categories="labels" :highlight-index="peakIndex" :format-y="formatY" :height="chartPx" /></div>
        <span v-else class="ht-empty text-caption text-medium-emphasis">{{ $t('workforce.widgets.hoursEmpty') }}</span>
      </div>
    </template>

    <!-- STANDARD / EXPANDED : aside + barres -->
    <div v-else class="hours-body flex-grow-1 d-flex gap-4" style="min-block-size: 0">
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

      <div ref="chartHost" class="ht-host flex-grow-1">
        <div v-if="!hasData" class="ht-empty text-center">
          <VIcon icon="tabler-chart-bar-off" size="30" class="text-disabled mb-2" />
          <div class="text-body-2 text-medium-emphasis">{{ $t('workforce.widgets.hoursEmpty') }}</div>
        </div>
        <div v-else class="ht-fill"><LzrApexChart class="w-100" type="bar" :series="series" :categories="labels" :highlight-index="peakIndex" :format-y="formatY" :height="chartPx" /></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; overflow: hidden; }
.hours-aside { inline-size: 150px; }
.ht-host { position: relative; flex: 1 1 auto; min-block-size: 44px; min-inline-size: 0; overflow: hidden; }
.ht-fill { position: absolute; inset: 0; }
.ht-empty { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
</style>
