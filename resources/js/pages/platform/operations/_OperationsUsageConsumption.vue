<script setup>
/**
 * ADR-601 — "Usage & Consumption" consolidated supervision page (Operations hub, tab=usage).
 *
 * Merges, into ONE professional screen, two complementary surfaces that were scattered:
 *  - Platform resource usage (daily-aggregated snapshots) → /usage/overview + /usage/company/{id}
 *    (view_usage_monitoring).
 *  - AI observability (real-time logs) → /ai/usage (view_ai).
 *
 * Section-level permission gating: each section only renders AND fetches when the caller holds the
 * right permission — no forbidden API call for a hidden section. Server middleware stays the real
 * guard. Aggregated (daily) and real-time (AI) numbers are shown in SEPARATE, clearly badged
 * sections and are never summed together (no double counting across snapshots vs live logs).
 */
import { useTheme } from 'vuetify'
import { hexToRgb } from '@layouts/utils'
import { usePlatformUsageStore } from '@/modules/platform-admin/usage/usage.store'
import { usePlatformAiUsageStore } from '@/modules/platform-admin/ai/ai-usage.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

const { t } = useI18n()
const auth = usePlatformAuthStore()
const usageStore = usePlatformUsageStore()
const aiStore = usePlatformAiUsageStore()
const vuetifyTheme = useTheme()

const canUsage = computed(() => auth.hasPermission('view_usage_monitoring'))
const canAi = computed(() => auth.hasPermission('view_ai'))

// ── Filters ──
const days = ref(30)                 // aggregated usage window (days)
const aiPeriod = ref('7d')           // AI observability window
const selectedCompany = ref(null)

// ── Loading ──
const loadingUsage = ref(false)
const loadingAi = ref(false)
const loadingCompany = ref(false)

// ── Formatters ──
const fmt = n => {
  const v = Number(n || 0)
  if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (v >= 1_000) return `${(v / 1_000).toFixed(1)}K`

  return String(v)
}

// ── Loaders (permission-gated — never call a forbidden endpoint) ──
async function loadUsage() {
  if (!canUsage.value) return
  loadingUsage.value = true
  try { await usageStore.fetchOverview(days.value) }
  catch { /* server already enforces; stay silent */ }
  finally { loadingUsage.value = false }
}

async function loadAi() {
  if (!canAi.value) return
  loadingAi.value = true
  try { await aiStore.fetchUsage(aiPeriod.value) }
  catch { /* noop */ }
  finally { loadingAi.value = false }
}

async function loadCompany() {
  if (!canUsage.value || !selectedCompany.value) return
  loadingCompany.value = true
  try { await usageStore.fetchCompanyDetail(selectedCompany.value, days.value) }
  catch { /* noop */ }
  finally { loadingCompany.value = false }
}

watch(days, () => { loadUsage(); loadCompany() })
watch(aiPeriod, loadAi)
watch(selectedCompany, loadCompany)
onMounted(() => { loadUsage(); loadAi() })

// ── Derived: aggregated usage ──
const overview = computed(() => usageStore.overview)
const totals = computed(() => overview.value?.totals ?? {})
const topCompanies = computed(() => overview.value?.top_ai_companies ?? [])
const trend = computed(() => overview.value?.daily_trend ?? [])

const companyOptions = computed(() => topCompanies.value.map(c => ({ title: c.company_name, value: c.company_id })))
const companyDetail = computed(() => usageStore.companyDetail)

// ── Derived: AI observability ──
const aiStats = computed(() => aiStore.usageStats ?? {})
const aiTokens = computed(() => (aiStats.value.total_input_tokens || 0) + (aiStats.value.total_output_tokens || 0))
const aiErrorRate = computed(() => {
  const total = aiStats.value.total_requests || 0

  return total ? Math.round(((aiStats.value.errors || 0) / total) * 100) : 0
})
const byProvider = computed(() => aiStore.byProvider ?? [])
const byModule = computed(() => Object.entries(aiStore.byModule ?? {}).map(([k, v]) => ({ module: k, total: v })))
const byCompanyAi = computed(() => aiStore.byCompany ?? [])
const recent = computed(() => aiStore.recentRequests ?? [])

// ── Chart (daily trend area) ──
const trendChartOptions = computed(() => {
  const theme = vuetifyTheme.current.value.colors
  const label = `rgba(${hexToRgb(theme['on-surface'])},0.7)`
  const grid = `rgba(${hexToRgb(theme['on-surface'])},0.12)`

  return {
    chart: { type: 'area', toolbar: { show: false }, parentHeightOffset: 0, fontFamily: 'inherit' },
    colors: [theme.primary, theme.info, theme.warning],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.05 } },
    legend: { labels: { colors: label }, markers: { offsetX: -3 }, itemMargin: { horizontal: 10 } },
    grid: { borderColor: grid, strokeDashArray: 4 },
    xaxis: {
      categories: trend.value.map(d => d.date),
      labels: { style: { colors: label }, rotate: -45, hideOverlappingLabels: true },
      axisBorder: { show: false }, axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: label }, formatter: v => fmt(v) } },
    tooltip: { theme: vuetifyTheme.current.value.dark ? 'dark' : 'light' },
  }
})
const trendSeries = computed(() => [
  { name: t('platformUsage.metrics.aiRequests'), data: trend.value.map(d => d.ai_requests) },
  { name: t('platformUsage.metrics.emails'), data: trend.value.map(d => d.emails_sent) },
  { name: t('platformUsage.metrics.apiRequests'), data: trend.value.map(d => d.api_requests) },
])

const companyTrendSeries = computed(() => [
  { name: t('platformUsage.metrics.aiRequests'), data: (companyDetail.value?.daily ?? []).map(d => d.ai_requests) },
  { name: t('platformUsage.metrics.emails'), data: (companyDetail.value?.daily ?? []).map(d => d.emails_sent) },
])
const companyTrendOptions = computed(() => ({
  ...trendChartOptions.value,
  colors: [vuetifyTheme.current.value.colors.primary, vuetifyTheme.current.value.colors.info],
  xaxis: { ...trendChartOptions.value.xaxis, categories: (companyDetail.value?.daily ?? []).map(d => d.date) },
}))

const dayOptions = [7, 30, 90]
const aiPeriodOptions = ['24h', '7d', '30d']
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('platformUsage.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('platformUsage.subtitle') }}
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VChip v-if="canUsage" size="small" color="info" variant="tonal" prepend-icon="tabler-calendar-stats">
          {{ t('platformUsage.aggregatedDaily') }}
        </VChip>
        <VChip v-if="canAi" size="small" color="success" variant="tonal" prepend-icon="tabler-activity">
          {{ t('platformUsage.realtime') }}
        </VChip>
      </div>
    </div>

    <!-- No permission at all -->
    <VCard v-if="!canUsage && !canAi" data-test="usage-no-access">
      <VCardText class="text-center py-12 text-medium-emphasis">
        <VIcon icon="tabler-lock" size="40" class="mb-2" />
        <div>{{ t('platformUsage.noAccess') }}</div>
      </VCardText>
    </VCard>

    <!-- ══ SECTION 1 — Platform overview & resources (view_usage_monitoring) ══ -->
    <template v-if="canUsage">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3" data-test="usage-section-overview">
        <h5 class="text-h5 d-flex align-center gap-2">
          <VIcon icon="tabler-chart-histogram" size="22" /> {{ t('platformUsage.sections.overview') }}
        </h5>
        <VBtnToggle v-model="days" color="primary" density="compact" variant="outlined" mandatory data-test="usage-period">
          <VBtn v-for="d in dayOptions" :key="d" :value="d" size="small">{{ d }}{{ t('platformUsage.daysShort') }}</VBtn>
        </VBtnToggle>
      </div>

      <VSkeletonLoader v-if="loadingUsage && !overview" type="image, table" />
      <template v-else>
        <VRow class="match-height mb-2">
          <VCol v-for="kpi in [
            { k:'aiRequests', v: fmt(totals.ai_requests), icon:'tabler-robot', c:'primary' },
            { k:'aiTokens', v: fmt(totals.ai_tokens), icon:'tabler-coin', c:'secondary' },
            { k:'emails', v: fmt(totals.emails_sent), icon:'tabler-mail', c:'info' },
            { k:'apiRequests', v: fmt(totals.api_requests), icon:'tabler-api', c:'warning' },
            { k:'members', v: fmt(totals.total_members), icon:'tabler-users', c:'success' },
            { k:'storage', v: `${totals.total_storage_gb ?? 0} GB`, icon:'tabler-database', c:'error' },
          ]" :key="kpi.k" cols="12" sm="6" md="4" lg="2">
            <VCard>
              <VCardText class="d-flex flex-column gap-1">
                <VAvatar :color="kpi.c" variant="tonal" rounded size="38"><VIcon :icon="kpi.icon" size="22" /></VAvatar>
                <div class="text-h5 mt-2">{{ kpi.v }}</div>
                <div class="text-caption text-medium-emphasis">{{ t(`platformUsage.metrics.${kpi.k}`) }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" lg="7">
            <VCard :title="t('platformUsage.dailyTrend')">
              <VCardText>
                <VueApexCharts v-if="trend.length" type="area" height="300" :options="trendChartOptions" :series="trendSeries" />
                <div v-else class="text-center py-10 text-medium-emphasis">{{ t('platformUsage.empty') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="12" lg="5">
            <VCard :title="t('platformUsage.topCompanies')">
              <VTable v-if="topCompanies.length" density="comfortable" data-test="usage-top-companies">
                <thead><tr><th>Company</th><th class="text-end">{{ t('platformUsage.metrics.aiRequests') }}</th><th class="text-end">{{ t('platformUsage.metrics.aiTokens') }}</th></tr></thead>
                <tbody>
                  <tr v-for="c in topCompanies" :key="c.company_id">
                    <td>{{ c.company_name }}</td>
                    <td class="text-end">{{ fmt(c.ai_requests) }}</td>
                    <td class="text-end">{{ fmt(c.ai_tokens) }}</td>
                  </tr>
                </tbody>
              </VTable>
              <VCardText v-else class="text-center py-10 text-medium-emphasis">{{ t('platformUsage.empty') }}</VCardText>
            </VCard>
          </VCol>
        </VRow>

        <!-- ══ SECTION 2 — Per-company analysis (view_usage_monitoring) ══ -->
        <div class="d-flex align-center gap-2 mt-6 mb-3" data-test="usage-section-company">
          <h5 class="text-h5 d-flex align-center gap-2"><VIcon icon="tabler-building" size="22" /> {{ t('platformUsage.sections.company') }}</h5>
        </div>
        <VCard>
          <VCardText>
            <AppSelect
              v-model="selectedCompany"
              :items="companyOptions"
              :placeholder="t('platformUsage.selectCompany')"
              clearable
              style="max-inline-size: 360px;"
              data-test="usage-company-select"
            />
            <VSkeletonLoader v-if="loadingCompany" type="text, image" class="mt-4" />
            <template v-else-if="selectedCompany && companyDetail">
              <VRow class="mt-2">
                <VCol v-for="kpi in [
                  { k:'aiRequests', v: fmt(companyDetail.totals.ai_requests) },
                  { k:'aiTokens', v: fmt(companyDetail.totals.ai_tokens) },
                  { k:'emails', v: fmt(companyDetail.totals.emails_sent) },
                  { k:'members', v: fmt(companyDetail.totals.active_members) },
                  { k:'storage', v: `${companyDetail.totals.storage_gb ?? 0} GB` },
                ]" :key="kpi.k" cols="6" md="2">
                  <div class="text-h6">{{ kpi.v }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t(`platformUsage.metrics.${kpi.k}`) }}</div>
                </VCol>
              </VRow>
              <VueApexCharts v-if="(companyDetail.daily || []).length" type="area" height="260" :options="companyTrendOptions" :series="companyTrendSeries" class="mt-4" />
            </template>
            <div v-else class="text-medium-emphasis mt-4">{{ t('platformUsage.selectCompanyHint') }}</div>
          </VCardText>
        </VCard>
      </template>
    </template>

    <!-- ══ SECTION 3 — AI observability (view_ai, real-time) ══ -->
    <template v-if="canAi">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mt-8 mb-3" data-test="usage-section-ai">
        <h5 class="text-h5 d-flex align-center gap-2">
          <VIcon icon="tabler-brain" size="22" /> {{ t('platformUsage.sections.ai') }}
          <VChip size="x-small" color="success" variant="tonal">{{ t('platformUsage.realtime') }}</VChip>
        </h5>
        <VBtnToggle v-model="aiPeriod" color="primary" density="compact" variant="outlined" mandatory data-test="usage-ai-period">
          <VBtn v-for="p in aiPeriodOptions" :key="p" :value="p" size="small">{{ p }}</VBtn>
        </VBtnToggle>
      </div>

      <VSkeletonLoader v-if="loadingAi && !aiStats.total_requests" type="image, table" />
      <template v-else>
        <VRow class="mb-2">
          <VCol v-for="kpi in [
            { k:'requests', v: fmt(aiStats.total_requests), icon:'tabler-robot', c:'primary' },
            { k:'tokens', v: fmt(aiTokens), icon:'tabler-coin', c:'secondary' },
            { k:'latency', v: `${aiStats.avg_latency_ms ?? 0} ms`, icon:'tabler-clock-bolt', c:'info' },
            { k:'errors', v: `${fmt(aiStats.errors)} (${aiErrorRate}%)`, icon:'tabler-alert-triangle', c: aiErrorRate > 5 ? 'error' : 'success' },
          ]" :key="kpi.k" cols="12" sm="6" md="3">
            <VCard>
              <VCardText class="d-flex align-center gap-3">
                <VAvatar :color="kpi.c" variant="tonal" rounded size="42"><VIcon :icon="kpi.icon" size="24" /></VAvatar>
                <div><div class="text-h5">{{ kpi.v }}</div><div class="text-caption text-medium-emphasis">{{ t(`platformUsage.ai.${kpi.k}`) }}</div></div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" md="6">
            <VCard :title="t('platformUsage.ai.byProvider')">
              <VTable v-if="byProvider.length" density="compact">
                <thead><tr><th>{{ t('platformUsage.ai.provider') }}</th><th class="text-end">{{ t('platformUsage.ai.requests') }}</th><th class="text-end">{{ t('platformUsage.ai.latency') }}</th><th class="text-end">{{ t('platformUsage.ai.errors') }}</th></tr></thead>
                <tbody><tr v-for="p in byProvider" :key="p.provider"><td>{{ p.provider }}</td><td class="text-end">{{ fmt(p.total) }}</td><td class="text-end">{{ p.avg_latency_ms }} ms</td><td class="text-end">{{ p.errors }}</td></tr></tbody>
              </VTable>
              <VCardText v-else class="text-center py-8 text-medium-emphasis">{{ t('platformUsage.empty') }}</VCardText>
            </VCard>
          </VCol>
          <VCol cols="12" md="6">
            <VCard :title="t('platformUsage.ai.byCompany')">
              <VTable v-if="byCompanyAi.length" density="compact" data-test="usage-ai-companies">
                <thead><tr><th>Company</th><th class="text-end">{{ t('platformUsage.ai.requests') }}</th><th class="text-end">{{ t('platformUsage.metrics.aiTokens') }}</th><th class="text-end">{{ t('platformUsage.ai.errors') }}</th></tr></thead>
                <tbody><tr v-for="c in byCompanyAi" :key="c.company_id"><td>{{ c.company_name }}</td><td class="text-end">{{ fmt(c.total_requests) }}</td><td class="text-end">{{ fmt(c.total_tokens) }}</td><td class="text-end">{{ c.errors }}</td></tr></tbody>
              </VTable>
              <VCardText v-else class="text-center py-8 text-medium-emphasis">{{ t('platformUsage.empty') }}</VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VCard :title="t('platformUsage.ai.recent')" class="mt-2">
          <VTable v-if="recent.length" density="compact" height="320" fixed-header>
            <thead><tr><th>{{ t('platformUsage.ai.provider') }}</th><th>{{ t('platformUsage.ai.model') }}</th><th>{{ t('platformUsage.ai.capability') }}</th><th class="text-end">{{ t('platformUsage.ai.latency') }}</th><th>{{ t('platformUsage.ai.status') }}</th><th>Module</th></tr></thead>
            <tbody>
              <tr v-for="r in recent" :key="r.id">
                <td>{{ r.provider }}</td><td>{{ r.model }}</td><td>{{ r.capability }}</td>
                <td class="text-end">{{ r.latency_ms }} ms</td>
                <td><VChip size="x-small" :color="r.status === 'success' ? 'success' : 'error'" variant="tonal">{{ r.status }}</VChip></td>
                <td class="text-caption">{{ r.module_key || '—' }}</td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-center py-8 text-medium-emphasis">{{ t('platformUsage.empty') }}</VCardText>
        </VCard>
      </template>
    </template>
  </div>
</template>
