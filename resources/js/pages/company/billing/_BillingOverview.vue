<script setup>
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = useCompanyBillingStore()

const isLoading = ref(true)

onMounted(async () => {
  try {
    await Promise.all([
      store.fetchOverview(),
      store.fetchNextInvoicePreview(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

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

const isCancellingChange = ref(false)

const formatDate = dateStr => {
  if (!dateStr) return ''

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
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

// Billing day
const billingDayOptions = [
  { title: '1', value: 1 },
  { title: '5', value: 5 },
  { title: '10', value: 10 },
  { title: '15', value: 15 },
  { title: '20', value: 20 },
  { title: '25', value: 25 },
]

const { toast } = useAppToast()

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

const statusColor = computed(() => {
  const colors = {
    active: 'success',
    trialing: 'info',
    past_due: 'error',
    pending_payment: 'warning',
  }

  return colors[subscriptionStatus.value] || 'secondary'
})

// Cancel subscription
const isCancelDialogVisible = ref(false)
const isCancelling = ref(false)

const canCancel = computed(() => {
  const status = subscriptionStatus.value

  return (status === 'active' || status === 'trialing') && !cancelAtPeriodEnd.value
})

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

    <template v-else-if="overview">
      <!-- Payment Failure Alert -->
      <VAlert
        v-if="hasPaymentIssue"
        type="error"
        variant="tonal"
        icon="tabler-alert-triangle"
        class="mb-6"
      >
        <VAlertTitle>
          {{ t('companyBilling.overview.paymentFailedTitle') }}
        </VAlertTitle>
        <span>
          {{ t('companyBilling.overview.paymentFailedDesc', { count: overview.outstanding_invoices }) }}
        </span>

        <template #append>
          <div class="d-flex gap-2">
            <VBtn
              variant="tonal"
              color="error"
              size="small"
              to="/company/billing/pay"
            >
              {{ t('companyBilling.overview.retryPayment') }}
            </VBtn>
            <VBtn
              variant="outlined"
              color="error"
              size="small"
              :to="{ name: 'company-billing-tab', params: { tab: 'payment-methods' } }"
            >
              {{ t('companyBilling.overview.changePaymentMethod') }}
            </VBtn>
          </div>
        </template>
      </VAlert>

      <!-- Trial Banner -->
      <VAlert
        v-if="overview.trial"
        type="info"
        variant="tonal"
        icon="tabler-clock"
        class="mb-6"
      >
        <VAlertTitle class="mb-1">
          {{ t('companyBilling.overview.trialTitle', { days: trialDaysRemaining }) }}
        </VAlertTitle>
        <VProgressLinear
          :model-value="trialProgress"
          color="info"
          rounded
          class="mt-2"
          height="6"
        />

        <template #append>
          <VBtn
            v-if="!paymentMethod"
            variant="tonal"
            color="info"
            size="small"
            :to="{ name: 'company-billing-tab', params: { tab: 'payment-methods' } }"
          >
            {{ t('companyBilling.overview.addPaymentMethod') }}
          </VBtn>
        </template>
      </VAlert>

      <!-- Cancellation pending -->
      <VAlert
        v-if="cancelAtPeriodEnd"
        type="warning"
        variant="tonal"
        icon="tabler-alert-circle"
        class="mb-6"
      >
        <VAlertTitle>
          {{ t('companyBilling.overview.cancelPendingTitle') }}
        </VAlertTitle>
        <span>
          {{ t('companyBilling.overview.cancelPendingDesc', { date: nextBillingDate }) }}
        </span>
      </VAlert>

      <!-- Scheduled Plan Change -->
      <VAlert
        v-if="scheduledChange"
        type="info"
        variant="tonal"
        icon="tabler-switch-horizontal"
        class="mb-6"
      >
        <VAlertTitle>
          {{ t('companyBilling.overview.scheduledChangeTitle') }}
        </VAlertTitle>
        <span>
          {{ t('companyBilling.overview.scheduledChangeDesc', {
            plan: scheduledChange.to_plan_key,
            interval: scheduledChange.interval_to === 'yearly' ? t('companyBilling.overview.yearly') : t('companyBilling.overview.monthly'),
            date: formatDate(scheduledChange.effective_at),
          }) }}
        </span>

        <template #append>
          <VBtn
            variant="outlined"
            color="error"
            size="small"
            :loading="isCancellingChange"
            @click="cancelScheduledPlanChange"
          >
            {{ t('companyBilling.overview.cancelScheduledChange') }}
          </VBtn>
        </template>
      </VAlert>

      <!-- ═══ Next Invoice Preview (hero card) ═══ -->
      <VCard
        v-if="preview"
        class="mb-6"
      >
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="primary"
              variant="tonal"
              size="48"
              rounded
            >
              <VIcon
                icon="tabler-file-invoice"
                size="28"
              />
            </VAvatar>
          </template>

          <VCardTitle class="text-h5">
            {{ t('companyBilling.nextInvoice.title') }}
          </VCardTitle>
          <VCardSubtitle>
            {{ nextBillingDate }}
          </VCardSubtitle>

          <template #append>
            <div class="text-right">
              <div class="text-h4 font-weight-bold">
                {{ formatMoney(preview.estimated_amount_due ?? preview.total, { currency: preview.currency }) }}
              </div>
              <span class="text-body-2 text-disabled">
                / {{ preview.plan?.interval === 'yearly' ? t('companyBilling.overview.yearly') : t('companyBilling.overview.monthly') }}
              </span>
            </div>
          </template>
        </VCardItem>

        <VDivider />

        <VCardText class="pa-0">
          <VList>
            <!-- Plan line -->
            <VListItem v-if="preview.plan">
              <template #prepend>
                <VAvatar
                  color="primary"
                  variant="tonal"
                  size="36"
                  class="me-3"
                >
                  <VIcon
                    icon="tabler-diamond"
                    size="20"
                  />
                </VAvatar>
              </template>

              <VListItemTitle class="font-weight-medium">
                {{ preview.plan.name }}
              </VListItemTitle>
              <VListItemSubtitle>
                {{ t('companyBilling.nextInvoice.planLabel') }}
              </VListItemSubtitle>

              <template #append>
                <span class="font-weight-medium">
                  {{ formatMoney(preview.plan.price, { currency: preview.currency }) }}
                </span>
              </template>
            </VListItem>

            <!-- Addon lines -->
            <VListItem
              v-for="addon in preview.addons"
              :key="addon.module_key"
            >
              <template #prepend>
                <VAvatar
                  color="secondary"
                  variant="tonal"
                  size="36"
                  class="me-3"
                >
                  <VIcon
                    icon="tabler-puzzle"
                    size="20"
                  />
                </VAvatar>
              </template>

              <VListItemTitle class="font-weight-medium">
                {{ addon.name }}
              </VListItemTitle>
              <VListItemSubtitle>
                {{ t('companyBilling.nextInvoice.addonLabel') }}
              </VListItemSubtitle>

              <template #append>
                <span class="font-weight-medium">
                  +{{ formatMoney(addon.price, { currency: preview.currency }) }}
                </span>
              </template>
            </VListItem>

            <VDivider />

            <!-- Subtotal line -->
            <VListItem>
              <VListItemTitle class="font-weight-medium">
                {{ t('companyBilling.nextInvoice.subtotal') }}
              </VListItemTitle>

              <template #append>
                <span class="font-weight-medium">
                  {{ formatMoney(preview.subtotal ?? preview.total, { currency: preview.currency }) }}
                </span>
              </template>
            </VListItem>

            <!-- Tax line -->
            <VListItem v-if="preview.tax_amount > 0">
              <VListItemTitle class="text-body-2 text-disabled">
                {{ t('companyBilling.nextInvoice.tax') }}
                <span v-if="preview.tax_rate_bps">({{ (preview.tax_rate_bps / 100).toFixed(1) }}%)</span>
              </VListItemTitle>

              <template #append>
                <span class="text-body-2">
                  +{{ formatMoney(preview.tax_amount, { currency: preview.currency }) }}
                </span>
              </template>
            </VListItem>

            <!-- Wallet credit line -->
            <VListItem v-if="preview.estimated_wallet_credit > 0">
              <VListItemTitle class="text-body-2 text-success">
                {{ t('companyBilling.nextInvoice.walletCredit') }}
              </VListItemTitle>

              <template #append>
                <span class="text-body-2 text-success">
                  -{{ formatMoney(preview.estimated_wallet_credit, { currency: preview.currency }) }}
                </span>
              </template>
            </VListItem>

            <VDivider />

            <!-- Amount due -->
            <VListItem>
              <VListItemTitle class="text-h6 font-weight-bold">
                {{ t('companyBilling.nextInvoice.estimatedAmountDue') }}
              </VListItemTitle>

              <template #append>
                <span class="text-h6 font-weight-bold">
                  {{ formatMoney(preview.estimated_amount_due ?? preview.total, { currency: preview.currency }) }}
                </span>
              </template>
            </VListItem>
          </VList>
        </VCardText>

        <VCardText
          v-if="preview.is_estimate"
          class="pt-0"
        >
          <p class="text-caption text-disabled mb-0">
            {{ t('companyBilling.nextInvoice.estimateDisclaimer') }}
          </p>
        </VCardText>
      </VCard>

      <VRow>
        <!-- Card — Current Plan -->
        <VCol
          cols="12"
          sm="6"
          md="4"
          lg="3"
        >
          <VCard class="h-100">
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="primary"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-diamond" />
                </VAvatar>
              </template>

              <VCardTitle>{{ t('companyBilling.overview.currentPlan') }}</VCardTitle>
            </VCardItem>

            <VCardText>
              <div class="d-flex align-center gap-2 mb-2">
                <h4 class="text-h4">
                  {{ planName }}
                </h4>
                <VChip
                  v-if="subscriptionStatus"
                  :color="statusColor"
                  size="small"
                  label
                >
                  {{ t(`subscriptionStatus.${subscriptionStatus}`) }}
                </VChip>
              </div>

              <p class="text-body-2 text-disabled mb-4">
                {{ planPrice }} / {{ planInterval }}
              </p>

              <div class="d-flex gap-2">
                <VBtn
                  variant="tonal"
                  color="primary"
                  size="small"
                  :to="{ name: 'company-plan' }"
                >
                  {{ t('companyBilling.overview.changePlan') }}
                </VBtn>
                <VBtn
                  v-if="canCancel"
                  variant="outlined"
                  color="error"
                  size="small"
                  @click="isCancelDialogVisible = true"
                >
                  {{ t('companyBilling.overview.cancelSubscription') }}
                </VBtn>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Card — Payment Method -->
        <VCol
          cols="12"
          sm="6"
          md="4"
          lg="3"
        >
          <VCard class="h-100">
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="info"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-credit-card" />
                </VAvatar>
              </template>

              <VCardTitle>{{ t('companyBilling.overview.paymentMethod') }}</VCardTitle>
            </VCardItem>

            <VCardText>
              <template v-if="paymentMethod">
                <p class="text-body-1 font-weight-medium mb-1">
                  {{ paymentMethodLabel }}
                </p>
                <p class="text-body-2 text-disabled mb-4">
                  {{ t('companyBilling.cardExpiry', { month: paymentMethod.exp_month, year: paymentMethod.exp_year }) }}
                </p>
              </template>

              <p
                v-else
                class="text-body-2 text-disabled mb-4"
              >
                {{ t('companyBilling.overview.noPaymentMethod') }}
              </p>

              <VBtn
                variant="tonal"
                color="info"
                size="small"
                :to="{ name: 'company-billing-tab', params: { tab: 'payment-methods' } }"
              >
                {{ t('companyBilling.overview.manage') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Card — Wallet -->
        <VCol
          cols="12"
          sm="6"
          md="4"
          lg="3"
        >
          <VCard class="h-100">
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="success"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-wallet" />
                </VAvatar>
              </template>

              <VCardTitle>{{ t('companyBilling.walletTitle') }}</VCardTitle>
            </VCardItem>

            <VCardText>
              <h4 class="text-h4 mb-4">
                {{ walletBalance }}
              </h4>

              <VBtn
                variant="tonal"
                color="success"
                size="small"
                :to="{ name: 'company-billing-tab', params: { tab: 'invoices' } }"
              >
                {{ t('companyBilling.overview.viewInvoices') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Card — Outstanding Invoices -->
        <VCol
          cols="12"
          sm="6"
          md="4"
          lg="3"
        >
          <VCard class="h-100">
            <VCardItem>
              <template #prepend>
                <VAvatar
                  :color="overview.outstanding_invoices > 0 ? 'error' : 'secondary'"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-file-invoice" />
                </VAvatar>
              </template>

              <VCardTitle>{{ t('companyBilling.invoices') }}</VCardTitle>
            </VCardItem>

            <VCardText>
              <template v-if="overview.outstanding_invoices > 0">
                <p class="text-body-1 font-weight-medium text-error mb-1">
                  {{ overview.outstanding_invoices }} {{ t('companyBilling.overview.unpaid') }}
                </p>
                <p class="text-body-2 text-disabled mb-4">
                  {{ t('companyBilling.overview.totalDue') }}:
                  {{ formatMoney(overview.outstanding_amount, { currency: overview.currency }) }}
                </p>
              </template>

              <p
                v-else
                class="text-body-2 text-disabled mb-4"
              >
                {{ t('companyBilling.overview.allPaid') }}
              </p>

              <VBtn
                variant="tonal"
                :color="overview.outstanding_invoices > 0 ? 'error' : 'secondary'"
                size="small"
                :to="{ name: 'company-billing-tab', params: { tab: 'invoices' } }"
              >
                {{ t('companyBilling.overview.viewInvoices') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Card — Billing Settings -->
        <VCol
          cols="12"
          sm="6"
          md="4"
          lg="3"
        >
          <VCard class="h-100">
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="warning"
                  variant="tonal"
                  size="40"
                  rounded
                >
                  <VIcon icon="tabler-calendar" />
                </VAvatar>
              </template>

              <VCardTitle>{{ t('companyBilling.overview.billingSettings') }}</VCardTitle>
            </VCardItem>

            <VCardText>
              <p class="text-body-2 text-disabled mb-3">
                {{ t('companyBilling.overview.billingDayDesc') }}
              </p>

              <AppSelect
                :model-value="overview?.subscription?.billing_anchor_day || ''"
                :items="billingDayOptions"
                :label="t('companyBilling.overview.billingDay')"
                @update:model-value="updateBillingDay"
              />
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- No subscription -->
    <VCard v-else>
      <VCardText class="text-center pa-8">
        <VAvatar
          size="64"
          variant="tonal"
          color="primary"
          class="mb-4"
        >
          <VIcon
            icon="tabler-diamond"
            size="32"
          />
        </VAvatar>
        <h5 class="text-h5 mb-2">
          {{ t('companyBilling.noSubscription') }}
        </h5>
        <p class="text-body-2 text-disabled mb-4">
          {{ t('companyBilling.overview.choosePlan') }}
        </p>
        <VBtn
          color="primary"
          :to="{ name: 'company-plan' }"
        >
          {{ t('companyBilling.overview.browsePlans') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Cancel subscription dialog -->
    <VDialog
      v-model="isCancelDialogVisible"
      max-width="450"
    >
      <VCard>
        <VCardTitle class="pt-5 px-5">
          {{ t('companyBilling.overview.cancelDialogTitle') }}
        </VCardTitle>
        <VCardText>
          {{ t('companyBilling.overview.cancelDialogDesc') }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="isCancelDialogVisible = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="isCancelling"
            @click="cancelSubscription"
          >
            {{ t('companyBilling.overview.cancelConfirmBtn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
