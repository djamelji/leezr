<script setup>
// ADR-746 / ADR-750 (P2.5) — « Companies & inscriptions » (platform). Données réelles
// (Company). Rendu ADAPTATIF par surface via le Presentation Engine partagé (ADR-748b) :
// micro = KPI + sparkline ; compact = KPI + barres ; standard/expanded = aside + barres ;
// wide = barres panoramiques + KPI en bandeau. Aucun moteur parallèle.
import { computed, ref } from 'vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { useMeasuredBlockSize } from '@/core/surface-engine/useMeasuredBlockSize'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const trend = computed(() => d.value.trend?.values ?? [])
const hasTrend = computed(() => trend.value.some(v => v > 0))
const series = computed(() => [{ name: 'Inscriptions', data: trend.value }])

const root = ref(null)
const chartHost = ref(null)
const { mode, isMicro, isCompact, isWide } = useSurfacePresentation(root, { viewport: computed(() => props.viewport) })
const chartPx = useMeasuredBlockSize(chartHost)
const asideStats = computed(() => !isMicro.value && !isCompact.value && !isWide.value)
</script>

<template>
  <div ref="root" class="widget-root d-flex flex-column" :class="[`mode-${mode}`, isMicro ? 'pa-3' : 'pa-5']">
    <div class="d-flex align-center justify-space-between gap-2 flex-shrink-0" :class="isMicro ? 'mb-1' : 'mb-2'">
      <div class="font-weight-medium text-high-emphasis text-truncate" :class="isMicro ? 'text-body-2' : 'text-body-1'">{{ t('platform.widgets.companiesOverview') }}</div>
      <VAvatar v-if="!isMicro" color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-building-community" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('platform.widgets.loadError')" />

    <!-- MICRO : KPI principal + sparkline -->
    <div v-else-if="isMicro" class="flex-grow-1 d-flex flex-column" style="min-block-size: 0">
      <div class="d-flex align-baseline gap-2">
        <span class="text-h5 font-weight-medium text-high-emphasis">{{ d.active ?? 0 }}</span>
        <span class="text-caption text-medium-emphasis text-truncate">{{ t('platform.widgets.companiesActive') }}</span>
      </div>
      <div ref="chartHost" class="cw-host">
        <div v-if="hasTrend" class="cw-fill"><LzrApexChart class="w-100" type="bar" mode="spark" :series="series" :categories="d.trend?.labels ?? []" :height="chartPx" /></div>
      </div>
    </div>

    <!-- COMPACT : KPI en ligne + barres -->
    <div v-else-if="isCompact" class="flex-grow-1 d-flex flex-column gap-1" style="min-block-size: 0">
      <div class="d-flex align-baseline gap-3 flex-wrap">
        <span class="d-flex align-baseline gap-1"><span class="text-h6 font-weight-medium text-high-emphasis">{{ d.active ?? 0 }}</span><span class="text-caption text-medium-emphasis">{{ t('platform.widgets.companiesActive') }}</span></span>
        <span class="text-caption text-medium-emphasis">+{{ d.new_30d ?? 0 }} / 30j</span>
        <span v-if="(d.suspended ?? 0) > 0" class="text-caption text-warning">{{ t('platform.widgets.suspendedN', { n: d.suspended ?? 0 }) }}</span>
      </div>
      <div ref="chartHost" class="cw-host">
        <div v-if="hasTrend" class="cw-fill"><LzrApexChart class="w-100" type="bar" :series="series" :categories="d.trend?.labels ?? []" :height="chartPx" /></div>
        <div v-else class="cw-empty text-caption text-medium-emphasis">{{ t('platform.widgets.noNewThisWeek') }}</div>
      </div>
    </div>

    <!-- WIDE : bandeau KPI + barres panoramiques -->
    <div v-else-if="isWide" class="flex-grow-1 d-flex flex-column gap-2" style="min-block-size: 0">
      <div class="d-flex align-center gap-5 flex-wrap flex-shrink-0">
        <div class="d-flex align-baseline gap-2"><span class="text-h5 font-weight-medium text-high-emphasis">{{ d.active ?? 0 }}</span><span class="text-caption text-medium-emphasis">{{ t('platform.widgets.companiesActive') }}</span></div>
        <div class="d-flex align-center gap-2"><VIcon icon="tabler-user-plus" color="success" size="18" /><span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.new30d', { n: d.new_30d ?? 0 }) }}</span></div>
        <div class="d-flex align-center gap-2"><VIcon icon="tabler-player-pause" :color="(d.suspended ?? 0) > 0 ? 'warning' : 'disabled'" size="18" /><span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.suspendedN', { n: d.suspended ?? 0 }) }}</span></div>
      </div>
      <div ref="chartHost" class="cw-host">
        <div v-if="hasTrend" class="cw-fill"><LzrApexChart class="w-100" type="bar" :series="series" :categories="d.trend?.labels ?? []" :height="chartPx" /></div>
        <div v-else class="cw-empty text-caption text-medium-emphasis">{{ t('platform.widgets.noNewThisWeek') }}</div>
      </div>
    </div>

    <!-- STANDARD / EXPANDED : aside KPI + barres -->
    <div v-else class="flex-grow-1 d-flex gap-5" style="min-block-size: 0">
      <div class="d-flex flex-column justify-center gap-3 flex-shrink-0" style="inline-size: 150px">
        <div>
          <div class="text-h4 font-weight-medium text-high-emphasis">{{ d.active ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('platform.widgets.companiesActive') }}</div>
        </div>
        <VDivider />
        <div class="d-flex flex-column gap-2">
          <div class="d-flex align-center gap-2"><VIcon icon="tabler-user-plus" color="success" size="18" /><span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.new30d', { n: d.new_30d ?? 0 }) }}</span></div>
          <div class="d-flex align-center gap-2"><VIcon icon="tabler-player-pause" :color="(d.suspended ?? 0) > 0 ? 'warning' : 'disabled'" size="18" /><span class="text-body-2 text-medium-emphasis">{{ t('platform.widgets.suspendedN', { n: d.suspended ?? 0 }) }}</span></div>
        </div>
      </div>
      <div ref="chartHost" class="cw-host flex-grow-1">
        <div v-if="hasTrend" class="cw-fill"><LzrApexChart class="w-100" type="bar" :series="series" :categories="d.trend?.labels ?? []" :height="chartPx" /></div>
        <div v-else class="cw-empty text-caption text-medium-emphasis">{{ t('platform.widgets.noNewThisWeek') }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.widget-root { block-size: 100%; overflow: hidden; }
.cw-host { position: relative; flex: 1 1 auto; min-block-size: 44px; min-inline-size: 0; overflow: hidden; }
.cw-fill { position: absolute; inset: 0; }
.cw-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; }
</style>
