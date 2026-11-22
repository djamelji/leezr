<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Inscriptions » for platform.signups (view_usage_monitoring), measured funnel ADR-849.
//   KPI size → registrations completed in 30 days, change vs the previous 30 days, daily sparkline
//   larger   → + started vs completed per day ; expanded → + the funnel steps
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'
import { pctChange } from './cockpitFormat'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const kpiSize = computed(() => rootW.value > 0 && (rootH.value < 200 || rootW.value < 300))
const d = computed(() => props.data || {})
const to = { name: 'platform-dashboard-tab', params: { tab: 'funnel' } }
const daily = computed(() => d.value.daily || [])
const delta = computed(() => pctChange(d.value.completed, d.value.completed_previous))
const STEP_ORDER = ['started', 'industry', 'plan', 'addons', 'company', 'account', 'completed']
const steps = computed(() => STEP_ORDER.filter(k => d.value.steps && k in d.value.steps).map(k => ({ key: k, n: d.value.steps[k] })))
const stepMax = computed(() => Math.max(1, ...steps.value.map(s => s.n)))
const empty = computed(() => !d.value.error && (d.value.started ?? 0) === 0)

const dayLabels = computed(() => daily.value.map(x => new Date(x.date).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })))
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <AppKpiTile
      v-if="kpiSize"
      bare
      :label="t('cockpit.signups.kpi')"
      icon="tabler-user-check"
      color="success"
      :value="d.error ? null : (d.completed ?? null)"
      :delta="delta"
      :delta-label="t('cockpit.signups.vsPrevious')"
      :context="t('cockpit.signups.context', { started: d.started ?? 0, rate: d.conversion ?? '—' })"
      :empty-hint="t('cockpit.unavailable')"
      :spark="daily.map(x => x.completed)"
      spark-type="bar"
      :to="to"
    />
    <AppWidgetShell
      v-else
      bare
      :title="t('cockpit.signups.title')"
      :subtitle="t('cockpit.signups.subtitle')"
      icon="tabler-user-check"
      color="success"
      :link="t('cockpit.signups.link')"
      :to="to"
      :state="loading && !data ? 'loading' : d.error ? 'error' : empty ? 'empty' : 'ready'"
      empty-icon="tabler-user-plus"
      :empty-title="t('cockpit.signups.emptyTitle')"
      :empty-text="t('cockpit.signups.emptyText')"
    >
      <template #default="{ mode }">
        <div class="d-flex align-end gap-6 flex-wrap mb-2">
          <div>
            <div class="cs-big">
              {{ d.completed }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('cockpit.signups.completed') }}
            </div>
          </div>
          <div>
            <div class="cs-mid">
              {{ d.started }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('cockpit.signups.started') }}
            </div>
          </div>
          <div>
            <div class="cs-mid text-success">
              {{ d.conversion === null ? '—' : `${d.conversion} %` }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('cockpit.signups.conversion') }}
            </div>
          </div>
        </div>
        <div class="d-flex gap-5 flex-grow-1 min-w-0">
          <div class="flex-grow-1 min-w-0">
            <LzrApexChart
              v-if="mode !== 'compact'"
              type="bar"
              :height="mode === 'expanded' ? 220 : 150"
              :series="[{ name: t('cockpit.signups.started'), data: daily.map(x => x.started) }, { name: t('cockpit.signups.completed'), data: daily.map(x => x.completed) }]"
              :categories="dayLabels"
            />
          </div>
          <div
            v-if="mode === 'expanded' || mode === 'compact'"
            class="cs-steps"
            :class="{ 'cs-steps--full': mode === 'compact' }"
          >
            <div
              v-for="(s, i) in steps"
              :key="s.key"
              class="cs-step"
            >
              <div class="d-flex justify-space-between text-body-2">
                <span>{{ t(`cockpit.signups.step.${s.key}`) }}</span><strong>{{ s.n }}</strong>
              </div>
              <div class="cs-track">
                <div
                  class="cs-fill lzr-grow"
                  :style="{ inlineSize: `${(s.n / stepMax) * 100}%`, animationDelay: `${i * 60}ms` }"
                />
              </div>
            </div>
          </div>
        </div>
      </template>
    </AppWidgetShell>
  </div>
</template>

<style scoped>
.cs-big { font-size: 2.25rem; font-weight: 700; letter-spacing: -.03em; line-height: 1; }
.cs-mid { font-size: 1.375rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; }
.cs-steps { flex: 0 0 230px; display: flex; flex-direction: column; gap: .5rem; }
.cs-steps--full { flex: 1 1 auto; }
.cs-track { block-size: 8px; border-radius: 999px; background: rgba(var(--v-theme-success), .12); overflow: hidden; margin-block-start: .25rem; }
.cs-fill { block-size: 100%; border-radius: 999px; background: linear-gradient(90deg, rgb(var(--v-theme-info)), rgb(var(--v-theme-success))); }
</style>
