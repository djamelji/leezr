<script setup>
// Leezr 5.0 cockpit (ADR-855) — « Sociétés » for platform.companies_overview (view_companies).
//   KPI size → active companies, new in 30 days, 30-day creation bars
//   larger   → + subscription status mix (active / trial / past due / other) + 30-day creation chart
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import { useSurfacePresentation } from '@/core/surface-engine/useSurfacePresentation'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const root = ref(null)
const { rootW, rootH } = useSurfacePresentation(root)
const kpiSize = computed(() => rootW.value > 0 && (rootH.value < 200 || rootW.value < 300))
const d = computed(() => props.data || {})
const to = { name: 'platform-companies' }

const mix = computed(() => {
  const m = d.value.subscription_mix || {}

  return [
    { key: 'active', label: t('cockpit.companies.mixActive'), n: m.active || 0, color: 'success' },
    { key: 'trialing', label: t('cockpit.companies.mixTrial'), n: m.trialing || 0, color: 'info' },
    { key: 'past_due', label: t('cockpit.companies.mixPastDue'), n: m.past_due || 0, color: 'error' },
    { key: 'other', label: t('cockpit.companies.mixOther'), n: m.other || 0, color: 'secondary' },
  ].filter(x => x.n > 0 || x.key !== 'other')
})

const mixTotal = computed(() => mix.value.reduce((s, x) => s + x.n, 0))

const dayLabels = computed(() => (d.value.trend_30d || []).map((_, i, a) => (i === a.length - 1 ? t('cockpit.today') : `J-${a.length - 1 - i}`)))
</script>

<template>
  <div
    ref="root"
    class="h-100"
  >
    <AppKpiTile
      v-if="kpiSize"
      bare
      :label="t('cockpit.companies.active')"
      icon="tabler-building-skyscraper"
      color="info"
      :value="d.error ? null : (d.active ?? null)"
      :context="t('cockpit.companies.context', { n: d.new_30d ?? 0, s: d.suspended ?? 0 })"
      :empty-hint="t('cockpit.unavailable')"
      :spark="d.trend_30d || []"
      spark-type="bar"
      :to="to"
    />
    <AppWidgetShell
      v-else
      bare
      :title="t('cockpit.companies.title')"
      :subtitle="t('cockpit.companies.subtitle')"
      icon="tabler-building-skyscraper"
      color="info"
      :link="t('cockpit.companies.link')"
      :to="to"
      :state="loading && !data ? 'loading' : d.error ? 'error' : 'ready'"
    >
      <template #default="{ mode }">
        <div class="d-flex align-end gap-6 flex-wrap mb-3">
          <div>
            <div class="cc-big">
              {{ d.active ?? '—' }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('cockpit.companies.activeOf', { total: d.total ?? 0 }) }}
            </div>
          </div>
          <div>
            <div class="cc-mid text-info">
              +{{ d.new_30d ?? 0 }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('cockpit.companies.new30') }}
            </div>
          </div>
          <div v-if="(d.suspended ?? 0) > 0">
            <div class="cc-mid text-warning">
              {{ d.suspended }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('cockpit.companies.suspended') }}
            </div>
          </div>
        </div>
        <div
          v-if="mixTotal"
          class="mb-3"
        >
          <div class="cc-bar">
            <RouterLink
              v-for="x in mix.filter(m => m.n > 0)"
              :key="x.key"
              :to="{ name: 'platform-billing-tab', params: { tab: 'subscriptions' } }"
              class="cc-bar__seg"
              :class="`bg-${x.color}`"
              :style="{ flexGrow: x.n }"
              :title="`${x.label} : ${x.n}`"
            />
          </div>
          <div class="d-flex flex-wrap gap-x-4 gap-y-1 mt-2 text-body-2">
            <span
              v-for="x in mix"
              :key="x.key"
              class="d-inline-flex align-center gap-1"
            ><span
              class="cc-dot"
              :class="`bg-${x.color}`"
            />{{ x.label }} <strong>{{ x.n }}</strong></span>
          </div>
        </div>
        <LzrApexChart
          v-if="mode !== 'compact' && (d.trend_30d || []).length"
          type="bar"
          :height="mode === 'expanded' ? 220 : 130"
          :series="[{ name: t('cockpit.companies.newShort'), data: d.trend_30d }]"
          :categories="dayLabels"
        />
      </template>
    </AppWidgetShell>
  </div>
</template>

<style scoped>
.cc-big { font-size: 2.25rem; font-weight: 700; letter-spacing: -.03em; line-height: 1; }
.cc-mid { font-size: 1.375rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; }
.cc-bar { display: flex; gap: 3px; block-size: 10px; border-radius: 999px; overflow: hidden; }
.cc-bar__seg { display: block; transition: filter .18s; }
.cc-bar__seg:hover { filter: brightness(1.12); }
.cc-dot { inline-size: 8px; block-size: 8px; border-radius: 50%; display: inline-block; }
</style>
