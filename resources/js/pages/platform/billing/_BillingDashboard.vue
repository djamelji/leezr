<script setup>
import ApexChartRevenueTrend from '../../../../ui/presets/charts/apex-chart/ApexChartRevenueTrend.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'
import { $api } from '@/utils/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = usePlatformPaymentsStore()

// ── KPI drill-down (ADR-584): explain the clicked dashboard KPI with reconciled operations ──
const drill = computed(() => route.query.drill || null)
const drillData = ref(null)
const drillLoading = ref(false)

async function loadDrilldown() {
  if (!drill.value) { drillData.value = null; return }
  drillLoading.value = true
  try {
    if (drill.value === 'receivables') {
      drillData.value = await $api('/platform/billing/drilldown/receivables')
    } else if (drill.value === 'revenue') {
      const period = route.query.period || '30d'
      drillData.value = await $api(`/platform/billing/drilldown/revenue?period=${encodeURIComponent(period)}`)
    }
  } catch { drillData.value = null }
  finally { drillLoading.value = false }
}

function closeDrilldown() {
  drillData.value = null
  router.replace({ path: '/platform/billing/overview' })
}

function goInvoices(status) {
  router.push({ path: '/platform/billing/invoices', query: status ? { status } : {} })
}

watch(drill, loadDrilldown, { immediate: true })

const isLoading = ref(true)
const metricsError = ref(false)
const recoveryError = ref(false)
const lastRefreshed = ref(null)

async function loadData() {
  isLoading.value = true
  metricsError.value = false
  recoveryError.value = false

  const results = await Promise.allSettled([
    store.fetchMetrics(),
    store.fetchRecoveryStatus(),
  ])

  if (results[0].status === 'rejected') metricsError.value = true
  if (results[1].status === 'rejected') recoveryError.value = true

  lastRefreshed.value = new Date()
  isLoading.value = false
}

onMounted(loadData)

const kpiCards = computed(() => {
  const m = store.metrics

  if (!m) return []

  const currency = m.currency || 'EUR'

  return [
    { title: t('platformBilling.metrics.mrrHT'), value: formatMoney(m.mrr, { currency }), icon: 'tabler-trending-up', color: 'primary' },
    { title: t('platformBilling.metrics.arrHT'), value: formatMoney(m.arr, { currency }), icon: 'tabler-chart-bar', color: 'success' },
    { title: t('platformBilling.metrics.activeSubscriptions'), value: m.active_subscriptions, icon: 'tabler-receipt', color: 'info' },
    { title: t('platformBilling.metrics.trialing'), value: m.trialing_subscriptions, icon: 'tabler-clock', color: 'warning' },
    { title: t('platformBilling.metrics.addonRevenueHT'), value: formatMoney(m.addon_mrr, { currency }), icon: 'tabler-puzzle', color: 'secondary' },
    { title: t('platformBilling.metrics.churn'), value: `${(m.churn_rate * 100).toFixed(1)}%`, icon: 'tabler-arrow-down-right', color: m.churn_rate > 0.05 ? 'error' : 'success' },
    { title: t('platformBilling.metrics.trialConversion'), value: m.trial_conversion_rate != null ? `${(m.trial_conversion_rate * 100).toFixed(1)}%` : '—', icon: 'tabler-user-check', color: 'primary' },
  ]
})

const fullLabels = computed(() => store.metrics?.mrr_history?.labels || [])
const fullSeries = computed(() => store.metrics?.mrr_history?.series || [])

// Period selector for the MRR trend (slice the last N months of real history — no fabricated data).
const trendMonths = ref(12)
const trendOptions = [{ label: '3M', value: 3 }, { label: '6M', value: 6 }, { label: '12M', value: 12 }]
const mrrLabels = computed(() => fullLabels.value.slice(-trendMonths.value))
const mrrSeries = computed(() => fullSeries.value.slice(-trendMonths.value))

// Lightweight inline-SVG sparkline (theme-aware, no margins) from a real series → polyline points
// over a 100×28 viewBox. Returns '' when there is nothing meaningful to plot.
function sparkPoints(series) {
  const s = (series || []).filter(v => typeof v === 'number')
  if (s.length < 2) return ''
  const min = Math.min(...s)
  const max = Math.max(...s)
  const span = max - min || 1
  const stepX = 100 / (s.length - 1)

  return s.map((v, i) => `${(i * stepX).toFixed(2)},${(26 - ((v - min) / span) * 24).toFixed(2)}`).join(' ')
}
// Cards that carry a real trend get a background sparkline (MRR, ARR share the MRR shape).
const cardSpark = computed(() => ({
  [t('platformBilling.metrics.mrrHT')]: sparkPoints(fullSeries.value),
  [t('platformBilling.metrics.arrHT')]: sparkPoints(fullSeries.value),
}))

const recovery = computed(() => store.recoveryStatus)

const alerts = computed(() => {
  const r = recovery.value
  if (!r) return []

  const items = []

  if (r.dead_letters > 0) {
    items.push({
      title: t('platformBilling.dashboard.deadLettersAlert', { count: r.dead_letters }),
      color: 'error',
      icon: 'tabler-mail-off',
    })
  }

  if (r.stuck_checkouts > 0) {
    items.push({
      title: t('platformBilling.dashboard.stuckCheckoutsAlert', { count: r.stuck_checkouts }),
      color: 'warning',
      icon: 'tabler-shopping-cart-off',
    })
  }

  if (r.overdue_confirmations > 0) {
    items.push({
      title: t('platformBilling.dashboard.overdueConfirmationsAlert', { count: r.overdue_confirmations }),
      color: 'warning',
      icon: 'tabler-clock-off',
    })
  }

  if (r.past_due_subscriptions > 0) {
    items.push({
      title: t('platformBilling.dashboard.pastDueSubsAlert', { count: r.past_due_subscriptions }),
      color: 'error',
      icon: 'tabler-alert-triangle',
    })
  }

  if (r.pending_approvals > 0) {
    items.push({
      title: t('platformBilling.dashboard.pendingApprovalsAlert', { count: r.pending_approvals }),
      color: 'info',
      icon: 'tabler-clock-check',
      action: 'pending_approvals',
    })
  }

  return items
})

const emit = defineEmits(['switchTab'])

const counters = computed(() => {
  const r = recovery.value
  if (!r) return []

  return [
    { title: t('platformBilling.dashboard.deadLetters'), value: r.dead_letters, color: r.dead_letters > 0 ? 'error' : 'success', icon: 'tabler-mail-off' },
    { title: t('platformBilling.dashboard.stuckCheckouts'), value: r.stuck_checkouts, color: r.stuck_checkouts > 0 ? 'warning' : 'success', icon: 'tabler-shopping-cart-off' },
    { title: t('platformBilling.dashboard.overdueConfirmations'), value: r.overdue_confirmations, color: r.overdue_confirmations > 0 ? 'warning' : 'success', icon: 'tabler-clock-off' },
    { title: t('platformBilling.dashboard.overdueInvoices'), value: r.overdue_invoices, color: r.overdue_invoices > 0 ? 'error' : 'success', icon: 'tabler-file-invoice' },
  ]
})

const refreshLabel = computed(() => {
  if (!lastRefreshed.value) return ''

  return t('platformBilling.dashboard.lastRefreshed', {
    time: lastRefreshed.value.toLocaleTimeString(),
  })
})
</script>

<template>
  <div>
    <!-- Tab purpose -->
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      <VAlertTitle>
        <VIcon
          icon="tabler-layout-dashboard"
          size="20"
          class="me-2"
        />
        {{ t('platformBilling.dashboard.headerTitle') }}
      </VAlertTitle>
      {{ t('platformBilling.dashboard.headerDesc') }}
    </VAlert>

    <!-- KPI drill-down explainer (ADR-584) — reconciled composition of the clicked KPI -->
    <VCard
      v-if="drill"
      class="mb-4"
      data-test="kpi-drilldown"
      :loading="drillLoading"
    >
      <VCardItem>
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>
            <VIcon icon="tabler-zoom-money" size="20" class="me-2" />
            {{ drill === 'receivables' ? t('platformBilling.drilldown.receivablesTitle') : t('platformBilling.drilldown.revenueTitle') }}
          </span>
          <VBtn size="small" variant="text" icon="tabler-x" :aria-label="t('common.close')" @click="closeDrilldown" />
        </VCardTitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <!-- Receivables: gross − write-offs = net, reconciled to ledger AR -->
        <template v-if="drill === 'receivables' && drillData">
          <div
            v-for="cur in drillData.by_currency"
            :key="cur.currency"
            class="mb-4"
            :data-test="`drill-receivables-${cur.currency}`"
          >
            <div class="d-flex flex-wrap gap-4 align-center mb-2">
              <div>
                <div class="text-caption text-medium-emphasis">{{ t('platformBilling.drilldown.grossDocuments') }}</div>
                <div class="text-h6" data-test="drill-gross">{{ formatMoney(cur.gross_documents_cents, { currency: cur.currency }) }}</div>
              </div>
              <div class="text-h5 text-disabled">−</div>
              <div>
                <div class="text-caption text-medium-emphasis">{{ t('platformBilling.drilldown.writtenOff') }}</div>
                <div class="text-h6 text-warning" data-test="drill-writeoff">{{ formatMoney(cur.written_off_cents, { currency: cur.currency }) }}</div>
              </div>
              <div class="text-h5 text-disabled">=</div>
              <div>
                <div class="text-caption text-medium-emphasis">{{ t('platformBilling.drilldown.netRecoverable') }}</div>
                <div class="text-h6 text-primary" data-test="drill-net">{{ formatMoney(cur.net_recoverable_cents, { currency: cur.currency }) }}</div>
              </div>
              <VChip
                :color="cur.reconciled ? 'success' : 'error'"
                size="small"
                variant="tonal"
                data-test="drill-reconciled"
              >
                {{ cur.reconciled ? t('platformBilling.drilldown.reconciled') : t('platformBilling.drilldown.gap', { amount: formatMoney(cur.gap_cents, { currency: cur.currency }) }) }}
              </VChip>
            </div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('platformBilling.drilldown.invoice') }}</th>
                  <th>{{ t('platformBilling.drilldown.status') }}</th>
                  <th class="text-end">{{ t('platformBilling.drilldown.amountDue') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in cur.invoices" :key="inv.invoice_id">
                  <td>{{ inv.number }}</td>
                  <td>
                    <VChip size="x-small" :color="inv.written_off ? 'warning' : 'default'" variant="tonal">
                      {{ inv.status }}<template v-if="inv.written_off"> · {{ t('platformBilling.drilldown.writeOffTag') }}</template>
                    </VChip>
                  </td>
                  <td class="text-end">{{ formatMoney(inv.amount_due_cents, { currency: cur.currency }) }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-file-invoice" @click="goInvoices('open')">
            {{ t('platformBilling.drilldown.openInvoices') }}
          </VBtn>
        </template>

        <!-- Revenue: contributing invoices (HT), total = KPI for the period -->
        <template v-else-if="drill === 'revenue' && drillData">
          <div
            v-for="cur in drillData.by_currency"
            :key="cur.currency"
            class="mb-4"
            :data-test="`drill-revenue-${cur.currency}`"
          >
            <div class="text-caption text-medium-emphasis">{{ t('platformBilling.drilldown.revenueHtPeriod', { period: drillData.period }) }}</div>
            <div class="text-h6 text-primary mb-2" data-test="drill-revenue-total">{{ formatMoney(cur.revenue_ht_cents, { currency: cur.currency }) }}</div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('platformBilling.drilldown.invoice') }}</th>
                  <th>{{ t('platformBilling.drilldown.issuedAt') }}</th>
                  <th class="text-end">{{ t('platformBilling.drilldown.revenueHt') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="inv in cur.invoices" :key="inv.invoice_id">
                  <td>{{ inv.number || `#${inv.invoice_id}` }}</td>
                  <td>{{ inv.issued_at || '—' }}</td>
                  <td class="text-end">{{ formatMoney(inv.ht_cents, { currency: cur.currency }) }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-file-invoice" @click="goInvoices()">
            {{ t('platformBilling.drilldown.allInvoices') }}
          </VBtn>
        </template>
      </VCardText>
    </VCard>

    <!-- Section errors (granular) -->
    <VAlert
      v-if="metricsError"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ t('platformBilling.dashboard.metricsError') }}
      <template #append>
        <VBtn
          size="small"
          variant="text"
          @click="loadData"
        >
          {{ t('platformBilling.dashboard.refresh') }}
        </VBtn>
      </template>
    </VAlert>

    <VAlert
      v-if="recoveryError"
      type="warning"
      variant="tonal"
      class="mb-4"
    >
      {{ t('platformBilling.dashboard.recoveryError') }}
      <template #append>
        <VBtn
          size="small"
          variant="text"
          @click="loadData"
        >
          {{ t('platformBilling.dashboard.refresh') }}
        </VBtn>
      </template>
    </VAlert>

    <VSkeletonLoader
      v-if="isLoading"
      type="card, card"
    />

    <template v-else>
      <!-- Alerts -->
      <VAlert
        v-for="(alert, idx) in alerts"
        :key="idx"
        :type="alert.color === 'info' ? 'info' : (alert.color === 'warning' ? 'warning' : 'error')"
        variant="tonal"
        :icon="alert.icon"
        class="mb-4"
      >
        {{ alert.title }}
        <template
          v-if="alert.action === 'pending_approvals'"
          #append
        >
          <VBtn
            size="small"
            variant="text"
            @click="emit('switchTab', 'subscriptions')"
          >
            {{ t('platformBilling.dashboard.viewPendingApprovals') }}
          </VBtn>
        </template>
      </VAlert>

      <!-- KPI Cards -->
      <VCard
        v-if="!metricsError"
        class="mb-6"
      >
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>
            <VIcon
              icon="tabler-report-money"
              class="me-2"
            />
            {{ t('platformBilling.metrics.title') }}
          </span>
          <span class="d-flex align-center gap-x-2">
            <span
              v-if="refreshLabel"
              class="text-body-2 text-disabled"
            >
              {{ refreshLabel }}
            </span>
            <VBtn
              icon="tabler-refresh"
              size="small"
              variant="text"
              :loading="isLoading"
              @click="loadData"
            />
          </span>
        </VCardTitle>
        <VCardText>
          <VRow class="card-grid card-grid-xs">
            <VCol
              v-for="card in kpiCards"
              :key="card.title"
              cols="6"
              md="4"
              lg
            >
              <VCard
                flat
                border
                class="text-center pa-4 kpi-card position-relative overflow-hidden"
              >
                <!-- Background sparkline (real history, no margins) — only where a trend exists -->
                <svg
                  v-if="cardSpark[card.title]"
                  class="kpi-spark"
                  viewBox="0 0 100 28"
                  preserveAspectRatio="none"
                  aria-hidden="true"
                >
                  <polyline
                    :points="cardSpark[card.title]"
                    fill="none"
                    :stroke="`rgb(var(--v-theme-${card.color}))`"
                    stroke-width="1.5"
                    vector-effect="non-scaling-stroke"
                  />
                </svg>
                <div class="position-relative">
                  <VAvatar
                    size="42"
                    variant="tonal"
                    :color="card.color"
                    class="mb-2"
                  >
                    <VIcon :icon="card.icon" />
                  </VAvatar>
                  <h4 class="text-h5 font-weight-bold">
                    {{ card.value }}
                  </h4>
                  <span class="text-body-2 text-disabled">{{ card.title }}</span>
                </div>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- MRR Trend Chart -->
      <VCard
        v-if="!metricsError && store.metrics?.mrr_history?.series?.length"
        class="mb-6"
      >
        <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
          <span>
            <VIcon
              icon="tabler-chart-area-line"
              class="me-2"
            />
            {{ t('platformBilling.dashboard.mrrTrend') }}
          </span>
          <VBtnToggle
            v-model="trendMonths"
            density="compact"
            variant="outlined"
            divided
            mandatory
            data-test="mrr-period"
          >
            <VBtn
              v-for="o in trendOptions"
              :key="o.value"
              :value="o.value"
              size="small"
            >
              {{ o.label }}
            </VBtn>
          </VBtnToggle>
        </VCardTitle>
        <VCardText>
          <ApexChartRevenueTrend
            :labels="mrrLabels"
            :series="mrrSeries"
            series-name="MRR"
            :y-formatter="val => formatMoney(val)"
            :height="280"
          />
        </VCardText>
      </VCard>

      <!-- System Health -->
      <VCard v-if="!recoveryError">
        <VCardTitle>
          <VIcon
            icon="tabler-activity-heartbeat"
            class="me-2"
          />
          {{ t('platformBilling.dashboard.systemHealth') }}
        </VCardTitle>
        <VCardText>
          <VRow>
            <VCol
              v-for="counter in counters"
              :key="counter.title"
              cols="6"
              md="3"
            >
              <div class="d-flex gap-x-4 align-center">
                <VAvatar
                  :color="counter.color"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon :icon="counter.icon" />
                </VAvatar>
                <div class="d-flex flex-column">
                  <h5 class="text-h5">
                    {{ counter.value }}
                  </h5>
                  <div class="text-body-2 text-disabled">
                    {{ counter.title }}
                  </div>
                </div>
              </div>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.kpi-card {
  transition: box-shadow .2s ease, transform .2s ease;
}
.kpi-card:hover {
  box-shadow: 0 4px 18px rgba(var(--v-theme-on-surface), 0.08);
  transform: translateY(-2px);
}
/* Background sparkline — flush to the card edges (no margins), subtle, theme-aware. */
.kpi-spark {
  position: absolute;
  inset-inline: 0;
  inset-block-end: 0;
  inline-size: 100%;
  block-size: 46%;
  opacity: 0.16;
  pointer-events: none;
}
</style>
