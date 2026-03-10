<script setup>
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { formatMoney } from '@/utils/money'
import { useAnalytics } from '@/composables/useAnalytics'
import EmptyState from '@/core/components/EmptyState.vue'
import BillingAlerts from './_BillingAlerts.vue'
import BillingNextInvoice from './_BillingNextInvoice.vue'
import BillingCards from './_BillingCards.vue'
import BillingCancelDialog from './_BillingCancelDialog.vue'

const { t } = useI18n()
const { toast } = useAppToast()
const store = useCompanyBillingStore()
const { track } = useAnalytics()

const isLoading = ref(true)
const loadError = ref(false)

const fetchAll = async () => {
  isLoading.value = true
  loadError.value = false
  try {
    await Promise.all([
      store.fetchOverview(),
      store.fetchNextInvoicePreview(),
    ])

    const days = store.overview?.trial?.days_remaining
    if (days != null) {
      track('trial_banner_seen', { days_remaining: days })
    }
  }
  catch {
    loadError.value = true
  }
  finally {
    isLoading.value = false
  }
}

onMounted(fetchAll)

// ── Derived state ──────────────────────────────────────────────
const overview = computed(() => store.overview)
const preview = computed(() => store.nextInvoicePreview)

const planName = computed(() => overview.value?.plan?.name ?? '—')

const planInterval = computed(() => {
  const interval = overview.value?.subscription?.interval
  if (!interval) return ''

  return interval === 'yearly'
    ? t('companyBilling.overview.yearly')
    : t('companyBilling.overview.monthly')
})

const planPrice = computed(() => {
  const plan = overview.value?.plan
  const interval = overview.value?.subscription?.interval
  if (!plan) return '—'
  const price = interval === 'yearly' ? plan.price_yearly : plan.price_monthly

  return formatMoney(price, { currency: overview.value?.currency })
})

const nextBillingDate = computed(() => {
  const date = preview.value?.next_billing_date
  if (!date) return '—'

  return new Date(date).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
})

const trialDaysRemaining = computed(() => overview.value?.trial?.days_remaining ?? 0)

const trialProgress = computed(() => {
  if (!overview.value?.trial) return 0
  const total = overview.value.trial.total_days ?? 14
  const used = total - trialDaysRemaining.value

  return Math.min(100, Math.round((used / total) * 100))
})

const paymentMethod = computed(() => overview.value?.payment_method)

const paymentMethodLabel = computed(() => {
  const pm = paymentMethod.value
  if (!pm) return null
  const brand = pm.brand ? pm.brand.charAt(0).toUpperCase() + pm.brand.slice(1) : 'Card'

  return `${brand} •••• ${pm.last4}`
})

const walletBalance = computed(() => {
  if (overview.value?.wallet_balance == null) return '—'

  return formatMoney(overview.value.wallet_balance, { currency: overview.value?.currency })
})

const hasPaymentIssue = computed(() => {
  const status = overview.value?.subscription?.status

  return status === 'past_due' || overview.value?.outstanding_invoices > 0
})

const subscriptionStatus = computed(() => overview.value?.subscription?.status)
const cancelAtPeriodEnd = computed(() => overview.value?.subscription?.cancel_at_period_end)
const scheduledChange = computed(() => overview.value?.subscription?.scheduled_change)
const pendingSub = computed(() => store.pendingSubscription)

const statusColor = computed(() => {
  const colors = {
    active: 'success',
    trialing: 'info',
    past_due: 'error',
    pending_payment: 'warning',
  }

  return colors[subscriptionStatus.value] || 'secondary'
})

const canCancel = computed(() => {
  const status = subscriptionStatus.value

  return (status === 'active' || status === 'trialing') && !cancelAtPeriodEnd.value
})

const billingDayOptions = [
  { title: '1', value: 1 },
  { title: '5', value: 5 },
  { title: '10', value: 10 },
  { title: '15', value: 15 },
  { title: '20', value: 20 },
  { title: '25', value: 25 },
]

// ── Actions ────────────────────────────────────────────────────
const isDismissing = ref(false)
const isCancellingChange = ref(false)
const isCancelDialogVisible = ref(false)
const isCancelling = ref(false)

const dismissRejected = async () => {
  isDismissing.value = true
  try {
    await store.dismissPendingSubscription()
    toast(t('companyBilling.overview.rejectedDismissed'), 'success')
    await store.fetchOverview()
  }
  catch {
    toast(t('companyBilling.overview.rejectedDismissFailed'), 'error')
  }
  finally {
    isDismissing.value = false
  }
}

const cancelScheduledPlanChange = async () => {
  isCancellingChange.value = true
  try {
    await store.cancelScheduledPlanChange()
    toast(t('companyBilling.overview.scheduledChangeCancelled'), 'success')
    await store.fetchOverview()
  }
  catch {
    toast(t('companyBilling.overview.scheduledChangeCancelFailed'), 'error')
  }
  finally {
    isCancellingChange.value = false
  }
}

const updateBillingDay = async day => {
  try {
    await store.setBillingDay(day)
    toast(t('companyBilling.overview.billingDayUpdated'), 'success')
    await store.fetchOverview()
  }
  catch {
    toast(t('companyBilling.overview.billingDayFailed'), 'error')
  }
}

const cancelSubscription = async () => {
  isCancelling.value = true
  try {
    const result = await store.cancelSubscription()
    isCancelDialogVisible.value = false
    const msg = result.timing === 'end_of_period'
      ? t('companyBilling.overview.cancelScheduledSuccess')
      : t('companyBilling.overview.cancelImmediateSuccess')

    toast(msg, 'success')
    await store.fetchOverview()
  }
  catch {
    toast(t('companyBilling.overview.cancelFailed'), 'error')
  }
  finally {
    isCancelling.value = false
  }
}
</script>

<template>
  <div>
    <VSkeletonLoader
      v-if="isLoading"
      type="card, card, card"
    />

    <VAlert
      v-else-if="loadError"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ t('common.loadError') }}
      <template #append>
        <VBtn
          variant="text"
          size="small"
          @click="fetchAll"
        >
          {{ t('common.retry') }}
        </VBtn>
      </template>
    </VAlert>

    <template v-else-if="overview">
      <BillingAlerts
        :overview="overview"
        :has-payment-issue="hasPaymentIssue"
        :trial-days-remaining="trialDaysRemaining"
        :trial-progress="trialProgress"
        :payment-method="paymentMethod"
        :cancel-at-period-end="cancelAtPeriodEnd"
        :next-billing-date="nextBillingDate"
        :scheduled-change="scheduledChange"
        :is-cancelling-change="isCancellingChange"
        :pending-sub="pendingSub"
        :is-dismissing="isDismissing"
        @cancel-scheduled-change="cancelScheduledPlanChange"
        @dismiss-rejected="dismissRejected"
      />

      <BillingNextInvoice
        v-if="preview"
        :preview="preview"
        :next-billing-date="nextBillingDate"
      />

      <BillingCards
        :overview="overview"
        :plan-name="planName"
        :plan-price="planPrice"
        :plan-interval="planInterval"
        :subscription-status="subscriptionStatus"
        :status-color="statusColor"
        :can-cancel="canCancel"
        :payment-method="paymentMethod"
        :payment-method-label="paymentMethodLabel"
        :wallet-balance="walletBalance"
        :billing-day-options="billingDayOptions"
        @open-cancel-dialog="isCancelDialogVisible = true"
        @update-billing-day="updateBillingDay"
      />
    </template>

    <VCard v-else>
      <EmptyState
        icon="tabler-diamond"
        :title="t('companyBilling.noSubscription')"
        :description="t('companyBilling.overview.choosePlan')"
        :cta-label="t('companyBilling.overview.browsePlans')"
        :cta-to="{ name: 'company-plan' }"
      />
    </VCard>

    <BillingCancelDialog
      :is-visible="isCancelDialogVisible"
      :is-cancelling="isCancelling"
      @update:is-visible="isCancelDialogVisible = $event"
      @confirm="cancelSubscription"
    />
  </div>
</template>
