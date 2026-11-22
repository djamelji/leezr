<script setup>
// ADR-856 (Leezr 5.0 L4) — « Synthèse expéditions » (shipments.overview, shipments.view). Real data : today, in_transit,
// late, unassigned, drivers_active, total, by_status. Error never shown as « 0 expéditions ».
//   KPI size → today + status bar  ·  standard → donut + status legend  ·  wide/expanded → day KPIs + donut + legend
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'company' }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const kpiSize = computed(() => rootW.value > 0 && (rootH.value < 200 || rootW.value < 300))
const d = computed(() => props.data || {})
const STATUS = { planned: 'info', in_transit: 'primary', delivered: 'success', draft: 'secondary', canceled: 'error' }
const segments = computed(() => Object.keys(STATUS).map(s => ({ s, c: Number((d.value.by_status || {})[s] || 0), color: STATUS[s] })).filter(x => x.c > 0))
const total = computed(() => d.value.total ?? 0)
const state = computed(() => (props.loading && !props.data ? 'loading' : d.value.error ? 'error' : d.value.total === undefined ? 'empty' : total.value === 0 ? 'empty' : 'ready'))
const to = { name: 'company-shipments' }

// ADR-856 — the donut follows the REAL height left under the header (rows are 60 px on the home, 80 px on the cockpit) : never over the title
const donutH = mode => Math.max(110, Math.min(mode === 'expanded' ? 230 : mode === 'compact' ? 130 : 180, (rootH.value || 300) - 96))

const dayStats = computed(() => [
  { label: t('shipments.widgets.todayLabel'), value: d.value.today ?? 0, tone: '' },
  { label: t('shipments.widgets.inTransit'), value: d.value.in_transit ?? 0, tone: 'primary' },
  { label: t('shipments.widgets.late'), value: d.value.late ?? 0, tone: (d.value.late ?? 0) ? 'error' : '' },
  { label: t('shipments.widgets.unassigned'), value: d.value.unassigned ?? 0, tone: (d.value.unassigned ?? 0) ? 'warning' : '' },
])
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <AppKpiTile
      v-if="kpiSize"
      bare
      :label="t('shipments.widgets.todayLabel')"
      icon="tabler-truck-delivery"
      :value="state === 'ready' || state === 'empty' ? (d.today ?? null) : null"
      :context="state === 'ready' ? t('shipments.widgets.totalN', { n: total }) : ''"
      :empty-hint="t('shipments.widgets.empty')"
      :breakdown="state === 'ready' ? dayStats.slice(1) : []"
      :loading="state === 'loading'"
      :error="state === 'error'"
      :to="to"
    />
    <AppWidgetShell
      v-else
      bare
      :title="t('shipments.widgets.overview')"
      :subtitle="state === 'ready' ? t('shipments.widgets.totalN', { n: total }) : ''"
      icon="tabler-truck-delivery"
      :link="t('l4.kpi.seeShipments')"
      :to="to"
      :state="state"
      empty-icon="tabler-package-off"
      :empty-title="t('shipments.widgets.empty')"
    >
      <template #default="{ mode }">
        <div
          class="so"
          :class="`so--${mode}`"
        >
          <div
            v-if="['wide', 'expanded'].includes(mode)"
            class="so-day"
          >
            <div
              v-for="s in dayStats"
              :key="s.label"
              class="so-stat"
            >
              <strong :class="s.tone ? `text-${s.tone}` : ''">{{ s.value }}</strong><span>{{ s.label }}</span>
            </div>
          </div>
          <div class="so-donut">
            <LzrApexChart
              type="donut"
              :series="segments.map(x => x.c)"
              :labels="segments.map(x => t(`shipments.status.${x.s}`))"
              :colors="segments.map(x => x.color)"
              :height="donutH(mode)"
              :legend="false"
            />
          </div>
          <div class="so-legend">
            <div
              v-for="x in segments"
              :key="x.s"
              class="so-leg"
            >
              <span
                class="so-dot"
                :class="`bg-${x.color}`"
              /><span class="flex-grow-1 text-truncate">{{ t(`shipments.status.${x.s}`) }}</span><strong>{{ x.c }}</strong>
            </div>
          </div>
        </div>
      </template>
    </AppWidgetShell>
  </div>
</template>

<style scoped>
.so { display: flex; align-items: center; gap: 1.25rem; flex: 1 1 auto; min-block-size: 0; }
.so--compact { gap: .75rem; }
.so--compact .so-donut { inline-size: 130px; }
.so--compact .so-legend { min-inline-size: 0; }
.so-day { display: grid; grid-template-columns: repeat(2, minmax(90px, 1fr)); gap: .5rem; flex: 0 0 auto; }
.so-stat { display: flex; flex-direction: column; padding: .5rem .75rem; border-radius: 10px; background: rgba(var(--v-theme-on-surface), .035); }
.so-stat strong { font-size: 1.375rem; letter-spacing: -.02em; line-height: 1.1; }
.so-stat span { font-size: .75rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.so-donut { inline-size: 200px; max-inline-size: 40%; flex-shrink: 0; display: flex; justify-content: center; }
.so--expanded .so-donut { inline-size: 240px; }
.so-donut :deep(.apexcharts-legend) { display: none; }
.so-legend { display: flex; flex-direction: column; gap: .375rem; flex: 1 1 auto; min-inline-size: 140px; }
.so-leg { display: flex; align-items: center; gap: .5rem; font-size: .875rem; }
.so-dot { inline-size: 9px; block-size: 9px; border-radius: 50%; flex-shrink: 0; }
</style>
