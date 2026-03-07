<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)

onMounted(async () => {
  try {
    await Promise.all([
      store.fetchMetrics(),
      store.fetchRecoveryStatus(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

const kpiCards = computed(() => {
  const m = store.metrics

  if (!m) return []

  return [
    { title: 'MRR', value: formatMoney(m.mrr), icon: 'tabler-trending-up', color: 'primary' },
    { title: 'ARR', value: formatMoney(m.arr), icon: 'tabler-chart-bar', color: 'success' },
    { title: t('platformBilling.metrics.activeSubscriptions'), value: m.active_subscriptions, icon: 'tabler-receipt', color: 'info' },
    { title: t('platformBilling.metrics.trialing'), value: m.trialing_subscriptions, icon: 'tabler-clock', color: 'warning' },
    { title: t('platformBilling.metrics.addonRevenue'), value: formatMoney(m.addon_mrr), icon: 'tabler-puzzle', color: 'secondary' },
    { title: t('platformBilling.metrics.churn'), value: `${(m.churn_rate * 100).toFixed(1)}%`, icon: 'tabler-arrow-down-right', color: m.churn_rate > 0.05 ? 'error' : 'success' },
  ]
})

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
</script>

<template>
  <div>
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
      <VCard class="mb-6">
        <VCardTitle>
          <VIcon
            icon="tabler-report-money"
            class="me-2"
          />
          {{ t('platformBilling.metrics.title') }}
        </VCardTitle>
        <VCardText>
          <VRow>
            <VCol
              v-for="card in kpiCards"
              :key="card.title"
              cols="6"
              md="4"
              lg="2"
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

      <!-- System Health -->
      <VCard>
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
