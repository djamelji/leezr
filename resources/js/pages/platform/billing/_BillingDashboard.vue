<script setup>
import ApexChartRevenueTrend from '../../../../ui/presets/charts/apex-chart/ApexChartRevenueTrend.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

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

const mrrLabels = computed(() => store.metrics?.mrr_history?.labels || [])
const mrrSeries = computed(() => store.metrics?.mrr_history?.series || [])

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
                class="text-center pa-4"
              >
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
        <VCardTitle>
          <VIcon
            icon="tabler-chart-area-line"
            class="me-2"
          />
          {{ t('platformBilling.dashboard.mrrTrend') }}
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
