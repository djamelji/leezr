<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Répartition par offre » for platform.plan_distribution (view_billing).
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const rows = computed(() => (d.value.distribution || []).filter(r => r.count > 0))
const TONES = ['primary', 'info', 'success', 'warning', 'secondary']
const colors = computed(() => rows.value.map((_, i) => TONES[i % TONES.length])) // theme tokens (LzrApexChart resolves them)
</script>

<template>
  <AppWidgetShell
    bare
    :title="t('cockpit.plans.title')"
    :subtitle="t('cockpit.plans.subtitle', { n: d.total ?? 0 })"
    icon="tabler-chart-donut-3"
    :link="t('cockpit.plans.link')"
    :to="{ name: 'platform-catalog-tab', params: { tab: 'plans' } }"
    :state="loading && !data ? 'loading' : d.error ? 'error' : !rows.length ? 'empty' : 'ready'"
    :empty-title="t('cockpit.plans.emptyTitle')"
  >
    <template #default="{ mode }">
      <div
        class="d-flex align-center gap-4 flex-grow-1"
        :class="{ 'flex-column': mode === 'compact' }"
      >
        <div class="cp-donut">
          <LzrApexChart
            type="donut"
            :height="mode === 'expanded' ? 210 : 160"
            :series="rows.map(r => r.count)"
            :colors="colors"
            :labels="rows.map(r => r.plan.charAt(0).toUpperCase() + r.plan.slice(1))"
          />
        </div>
        <div class="d-flex flex-column gap-2 flex-grow-1 min-w-0 w-100">
          <div
            v-for="(r, i) in rows"
            :key="r.plan"
            class="d-flex align-center gap-2 text-body-2"
          >
            <span
              class="cp-dot"
              :class="`bg-${TONES[i % TONES.length]}`"
            /><span class="flex-grow-1 text-capitalize">{{ r.plan }}</span><strong>{{ r.count }}</strong>
            <span class="text-medium-emphasis cp-pct">{{ Math.round((r.count / (d.total || 1)) * 100) }} %</span>
          </div>
        </div>
      </div>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.cp-donut { inline-size: 180px; flex-shrink: 0; }
.cp-donut :deep(.apexcharts-legend) { display: none; } /* the list beside it carries counts and shares */
.cp-dot { inline-size: 10px; block-size: 10px; border-radius: 50%; display: inline-block; }
.cp-pct { min-inline-size: 3.25rem; text-align: end; }
</style>
