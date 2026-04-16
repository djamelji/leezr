<script setup>
import { formatDate } from '@/utils/datetime'

const props = defineProps({
  overview: { type: Object, default: null },
  hasPaymentIssue: { type: Boolean, default: false },
  trialDaysRemaining: { type: Number, default: 0 },
  trialProgress: { type: Number, default: 0 },
  paymentMethod: { type: Object, default: null },
  cancelAtPeriodEnd: { type: Boolean, default: false },
  nextBillingDate: { type: String, default: '—' },
  scheduledChange: { type: Object, default: null },
  isCancellingChange: { type: Boolean, default: false },
  pendingSub: { type: Object, default: null },
  isDismissing: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel-scheduled-change', 'dismiss-rejected'])

const { t } = useI18n()

</script>

<template>
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
          v-can="'billing.manage'"
          variant="tonal"
          color="error"
          size="small"
          to="/company/billing/pay"
        >
          {{ t('companyBilling.overview.retryPayment') }}
        </VBtn>
        <VBtn
          v-can="'billing.manage'"
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
        v-can="'billing.manage'"
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
        v-can="'billing.manage'"
        variant="outlined"
        color="error"
        size="small"
        :loading="isCancellingChange"
        @click="emit('cancel-scheduled-change')"
      >
        {{ t('companyBilling.overview.cancelScheduledChange') }}
      </VBtn>
    </template>
  </VAlert>

  <!-- Pending Upgrade Request (ADR-289) -->
  <VAlert
    v-if="pendingSub?.status === 'pending'"
    type="warning"
    variant="tonal"
    prominent
    class="mb-6"
  >
    <VAlertTitle>
      {{ t('companyPlan.pendingApproval') }}
    </VAlertTitle>
    <span>
      {{ t('companyPlan.pendingMessage', { plan: pendingSub.plan_key }) }}
    </span>
  </VAlert>

  <!-- Rejected Upgrade Request (ADR-289) -->
  <VAlert
    v-if="pendingSub?.status === 'rejected'"
    type="error"
    variant="tonal"
    prominent
    class="mb-6"
  >
    <VAlertTitle>
      {{ t('companyPlan.rejectedTitle') }}
    </VAlertTitle>
    <span>
      {{ t('companyPlan.rejectedMessage', { plan: pendingSub.plan_key }) }}
    </span>
    <template #append>
      <VBtn
        v-can="'billing.manage'"
        variant="outlined"
        color="error"
        size="small"
        :loading="isDismissing"
        @click="emit('dismiss-rejected')"
      >
        {{ t('companyPlan.dismissRejected') }}
      </VBtn>
    </template>
  </VAlert>
</template>
