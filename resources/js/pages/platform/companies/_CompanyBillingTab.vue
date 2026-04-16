<script setup>
import { loadStripe } from '@stripe/stripe-js'
import { formatDate } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'
import StatusChip from '@/core/components/StatusChip.vue'
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  billing: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  companyId: { type: Number, required: true },
})

const emit = defineEmits(['adjust-wallet', 'refresh'])

const { t } = useI18n()
const companiesStore = usePlatformCompaniesStore()
const { toast } = useAppToast()

// ─── Widget KPIs ────────────────────────────────────
const widgetData = ref(null)
const widgetLoading = ref(false)

const loadWidgets = async () => {
  widgetLoading.value = true
  try {
    const data = await companiesStore.fetchCompanyWidgets(props.companyId, [
      { key: 'billing.revenue_mtd', scope: 'company', company_id: props.companyId },
      { key: 'billing.ar_outstanding', scope: 'company', company_id: props.companyId },
      { key: 'billing.failed_payments_7d', scope: 'company', company_id: props.companyId },
    ])

    widgetData.value = {}
    for (const r of (data.results || [])) {
      if (r.data)
        widgetData.value[r.key] = r.data
    }
  }
  catch {
    // Widgets are nice-to-have, don't fail
  }
  finally {
    widgetLoading.value = false
  }
}

// ─── Payment Methods ────────────────────────────────
const paymentMethods = ref([])
const pmLoading = ref(false)
const pmActionLoading = ref(null)

const loadPaymentMethods = async () => {
  pmLoading.value = true
  try {
    const data = await companiesStore.fetchPaymentMethods(props.companyId)

    paymentMethods.value = data.cards || []
  }
  catch {
    // Handled by billing section
  }
  finally {
    pmLoading.value = false
  }
}

const setDefaultPm = async pm => {
  pmActionLoading.value = pm.id
  try {
    await companiesStore.setDefaultPaymentMethod(props.companyId, pm.id)
    toast(t('platformCompanyDetail.billing.pmDefaultUpdated'), 'success')
    await loadPaymentMethods()
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.billing.pmActionFailed'), 'error')
  }
  finally {
    pmActionLoading.value = null
  }
}

const deletePm = async pm => {
  pmActionLoading.value = pm.id
  try {
    await companiesStore.deletePaymentMethod(props.companyId, pm.id)
    toast(t('platformCompanyDetail.billing.pmDeleted'), 'success')
    await loadPaymentMethods()
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.billing.pmActionFailed'), 'error')
  }
  finally {
    pmActionLoading.value = null
  }
}

// ─── Add Payment Method (Stripe Elements) ──────
const showAddCard = ref(false)
const addCardLoading = ref(false)
const addCardError = ref('')
const cardElementRef = ref(null)
let stripe = null
let cardElement = null

const openAddCard = async () => {
  showAddCard.value = true
  addCardError.value = ''
  addCardLoading.value = true

  try {
    const data = await companiesStore.createAdminSetupIntent(props.companyId)

    if (!data?.publishable_key) {
      addCardError.value = t('platformCompanyDetail.billing.stripeNotConfigured')
      return
    }

    if (!stripe)
      stripe = await loadStripe(data.publishable_key)

    const elements = stripe.elements()

    await nextTick()

    cardElement = elements.create('card', {
      hidePostalCode: true,
      style: {
        base: { fontSize: '16px', color: '#424242', '::placeholder': { color: '#aab7c4' } },
      },
    })
    cardElement.mount(cardElementRef.value)
  }
  catch (e) {
    addCardError.value = e?.data?.message || e?.message || t('platformCompanyDetail.billing.pmActionFailed')
  }
  finally {
    addCardLoading.value = false
  }
}

const saveCard = async () => {
  if (!stripe || !cardElement) return

  addCardLoading.value = true
  addCardError.value = ''

  try {
    const intentData = await companiesStore.createAdminSetupIntent(props.companyId)
    const { error, setupIntent } = await stripe.confirmCardSetup(intentData.client_secret, {
      payment_method: { card: cardElement },
    })

    if (error) {
      addCardError.value = error.message
      return
    }

    await companiesStore.confirmAdminPaymentMethod(props.companyId, setupIntent.payment_method)
    toast(t('platformCompanyDetail.billing.pmAdded'), 'success')
    closeAddCard()
    await loadPaymentMethods()
  }
  catch (e) {
    addCardError.value = e?.data?.message || e?.message || t('platformCompanyDetail.billing.pmActionFailed')
  }
  finally {
    addCardLoading.value = false
  }
}

const closeAddCard = () => {
  showAddCard.value = false
  addCardError.value = ''
  if (cardElement) {
    cardElement.unmount()
    cardElement = null
  }
}

// ─── Invoice Actions ────────────────────────────────
const invoiceActionLoading = ref(null)
const confirmDialog = ref(false)
const confirmAction = ref(null)
const confirmInvoice = ref(null)
const confirmActionLabel = ref('')

const openConfirmDialog = (action, invoice, label) => {
  confirmAction.value = action
  confirmInvoice.value = invoice
  confirmActionLabel.value = label
  confirmDialog.value = true
}

const executeConfirmedAction = async () => {
  if (!confirmAction.value || !confirmInvoice.value) return
  await executeInvoiceAction(confirmAction.value, confirmInvoice.value)
  confirmDialog.value = false
  confirmAction.value = null
  confirmInvoice.value = null
}

const executeInvoiceAction = async (action, invoice) => {
  invoiceActionLoading.value = `${invoice.id}-${action}`
  try {
    let data
    switch (action) {
      case 'retry':
        data = await companiesStore.retryInvoicePayment(invoice.id)
        break
      case 'mark-paid':
        data = await companiesStore.markInvoicePaidOffline(invoice.id)
        break
      case 'void':
        data = await companiesStore.voidInvoice(invoice.id)
        break
      case 'credit-note':
        data = await companiesStore.issueCreditNote(invoice.id)
        break
    }
    toast(data?.message || t('common.success'), 'success')
    emit('refresh')
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.billing.invoiceActionFailed'), 'error')
  }
  finally {
    invoiceActionLoading.value = null
  }
}

// ─── Subscription Actions ───────────────────────────
const subActionLoading = ref(false)
const extendTrialDialog = ref(false)
const extendTrialDays = ref(7)

// Cancel confirmation dialog (ADR-446: Billing Safety)
const isCancelDialogOpen = ref(false)
const cancelPreview = ref(null)
const cancelPreviewLoading = ref(false)

const openCancelDialog = async () => {
  isCancelDialogOpen.value = true
  cancelPreviewLoading.value = true
  try {
    const { data } = await useApi(`/platform/api/companies/${props.companyId}/subscription/cancel-preview`)

    cancelPreview.value = data.value
  }
  catch {
    cancelPreview.value = null
  }
  finally {
    cancelPreviewLoading.value = false
  }
}

const cancelSubscription = async () => {
  isCancelDialogOpen.value = false
  subActionLoading.value = true
  try {
    const data = await companiesStore.cancelSubscription(props.companyId)

    toast(data.message, 'success')
    emit('refresh')
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.billing.subActionFailed'), 'error')
  }
  finally {
    subActionLoading.value = false
  }
}

// Undo cancel confirmation dialog (ADR-446: Billing Safety)
const isUndoCancelDialogOpen = ref(false)

const undoCancelSubscription = async () => {
  isUndoCancelDialogOpen.value = false
  subActionLoading.value = true
  try {
    const data = await companiesStore.undoCancelSubscription(props.companyId)

    toast(data.message, 'success')
    emit('refresh')
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.billing.subActionFailed'), 'error')
  }
  finally {
    subActionLoading.value = false
  }
}

const submitExtendTrial = async () => {
  subActionLoading.value = true
  try {
    const data = await companiesStore.extendTrial(props.companyId, extendTrialDays.value)

    toast(data.message, 'success')
    extendTrialDialog.value = false
    emit('refresh')
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.billing.subActionFailed'), 'error')
  }
  finally {
    subActionLoading.value = false
  }
}

// ─── Wallet History ─────────────────────────────────
const walletHistory = ref([])
const walletHistoryLoading = ref(false)

const loadWalletHistory = async () => {
  walletHistoryLoading.value = true
  try {
    const data = await companiesStore.fetchWalletHistory(props.companyId)

    walletHistory.value = data.transactions || []
  }
  catch {
    // Optional
  }
  finally {
    walletHistoryLoading.value = false
  }
}

// ─── Helpers ────────────────────────────────────────
const statusColor = status => {
  const map = { paid: 'success', open: 'info', overdue: 'error', voided: 'secondary', draft: 'secondary' }

  return map[status] || 'secondary'
}

const subStatusColor = status => {
  const map = { active: 'success', trialing: 'warning', past_due: 'error', cancelled: 'secondary', suspended: 'error' }

  return map[status] || 'secondary'
}

const pmBrandIcon = brand => {
  const map = { visa: 'tabler-brand-visa', mastercard: 'tabler-brand-mastercard', amex: 'tabler-credit-card' }

  return map[brand?.toLowerCase()] || 'tabler-credit-card'
}

const walletTxColor = type => type === 'credit' ? 'success' : 'error'

const copyToClipboard = async text => {
  try {
    await navigator.clipboard.writeText(text)
    toast(t('common.copied'), 'success')
  }
  catch {
    // fallback silently
  }
}

const invoiceHeaders = computed(() => [
  { title: '#', key: 'number', width: 120 },
  { title: t('common.status'), key: 'status', width: 100 },
  { title: t('platformCompanyDetail.billing.amount'), key: 'amount_due', width: 120 },
  { title: t('platformCompanyDetail.billing.issued'), key: 'issued_at', width: 130 },
  { title: t('platformCompanyDetail.billing.due'), key: 'due_at', width: 130 },
  { title: t('platformCompanyDetail.billing.paidAt'), key: 'paid_at', width: 130 },
  { title: '', key: 'actions', width: 60, sortable: false },
])

const walletHistoryHeaders = computed(() => [
  { title: t('common.date'), key: 'created_at', width: 130 },
  { title: t('common.type'), key: 'type', width: 80 },
  { title: t('platformCompanyDetail.billing.amount'), key: 'amount', width: 120 },
  { title: t('common.description'), key: 'description' },
  { title: t('platformCompanyDetail.billing.balance'), key: 'balance_after', width: 120 },
])

// ─── Load data on mount ─────────────────────────────
watch(() => props.billing, val => {
  if (val) {
    loadPaymentMethods()
    loadWalletHistory()
    loadWidgets()
  }
}, { immediate: true })
</script>

<template>
  <div>
    <!-- Loading -->
    <div v-if="loading" class="text-center pa-8">
      <VProgressCircular indeterminate />
    </div>

    <template v-else-if="billing">
      <!-- KPI Widgets -->
      <VRow v-if="widgetData" class="mb-4">
        <VCol cols="12" md="4">
          <VCard flat border class="text-center pa-3">
            <div class="text-body-2 text-disabled">
              {{ t('platformCompanyDetail.billing.revenueMtd') }}
            </div>
            <div class="text-h5 mt-1 text-success">
              {{ formatMoney(widgetData['billing.revenue_mtd']?.revenue ?? 0, { currency: billing.currency }) }}
            </div>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard flat border class="text-center pa-3">
            <div class="text-body-2 text-disabled">
              {{ t('platformCompanyDetail.billing.arOutstanding') }}
            </div>
            <div class="text-h5 mt-1" :class="(widgetData['billing.ar_outstanding']?.outstanding ?? 0) > 0 ? 'text-warning' : ''">
              {{ formatMoney(widgetData['billing.ar_outstanding']?.outstanding ?? 0, { currency: billing.currency }) }}
            </div>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard flat border class="text-center pa-3">
            <div class="text-body-2 text-disabled">
              {{ t('platformCompanyDetail.billing.failedPayments7d') }}
            </div>
            <div class="text-h5 mt-1" :class="(widgetData['billing.failed_payments_7d']?.count ?? 0) > 0 ? 'text-error' : ''">
              {{ widgetData['billing.failed_payments_7d']?.count ?? 0 }}
            </div>
          </VCard>
        </VCol>
      </VRow>

      <!-- Subscription Card -->
      <VCard class="mb-4" flat border>
        <VCardTitle class="d-flex align-center">
          <VIcon icon="tabler-credit-card" class="me-2" />
          {{ t('platformCompanyDetail.billing.subscription') }}
        </VCardTitle>
        <VCardText v-if="billing.subscription">
          <VRow>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('common.status') }}
              </div>
              <VChip :color="subStatusColor(billing.subscription.status)" size="small">
                {{ billing.subscription.status }}
              </VChip>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.interval') }}
              </div>
              <span class="text-body-1">{{ billing.subscription.interval }}</span>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.periodEnd') }}
              </div>
              <span class="text-body-1">{{ formatDate(billing.subscription.current_period_end) }}</span>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.walletBalance') }}
              </div>
              <div class="d-flex align-center gap-2">
                <span class="text-body-1 font-weight-medium text-success">
                  {{ formatMoney(billing.wallet_balance ?? 0, { currency: billing.currency }) }}
                </span>
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="primary"
                  @click="emit('adjust-wallet')"
                >
                  <VIcon icon="tabler-plus" size="16" />
                  <VTooltip activator="parent">
                    {{ t('platformCompanyDetail.wallet.title') }}
                  </VTooltip>
                </VBtn>
              </div>
            </VCol>
          </VRow>

          <!-- Provider & Trial Details -->
          <VDivider class="my-3" />
          <VRow class="mb-2">
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.planKey') }}
              </div>
              <VChip size="small" color="primary" variant="tonal">
                {{ billing.subscription.plan_key }}
              </VChip>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.periodStart') }}
              </div>
              <span class="text-body-1">{{ formatDate(billing.subscription.current_period_start) }}</span>
            </VCol>
            <VCol v-if="billing.subscription.trial_ends_at" cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.trialEndsAt') }}
              </div>
              <span class="text-body-1">{{ formatDate(billing.subscription.trial_ends_at) }}</span>
            </VCol>
            <VCol v-if="billing.subscription.coupon" cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.activeCoupon') }}
              </div>
              <VChip size="small" color="info" variant="tonal">
                {{ billing.subscription.coupon.code }}
                <span class="ms-1 text-disabled">
                  ({{ billing.subscription.coupon.type === 'percentage'
                    ? `${billing.subscription.coupon.value}%`
                    : formatMoney(billing.subscription.coupon.value, { currency: billing.currency })
                  }})
                </span>
              </VChip>
            </VCol>
          </VRow>

          <!-- Provider IDs (provider-agnostic: URLs from backend adapter) -->
          <VRow v-if="billing.provider_customer_id || billing.subscription.provider_subscription_id" class="mb-2">
            <VCol v-if="billing.provider_customer_id" cols="12" md="6">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.providerCustomerId') }}
              </div>
              <div class="d-flex align-center gap-1">
                <code class="text-body-2">{{ billing.provider_customer_id }}</code>
                <VBtn icon size="x-small" variant="text" @click="copyToClipboard(billing.provider_customer_id)">
                  <VIcon icon="tabler-copy" size="14" />
                </VBtn>
                <VBtn
                  v-if="billing.provider_links?.customer_url"
                  icon
                  size="x-small"
                  variant="text"
                  :href="billing.provider_links.customer_url"
                  target="_blank"
                  tag="a"
                >
                  <VIcon icon="tabler-external-link" size="14" />
                  <VTooltip activator="parent">
                    {{ t('platformCompanyDetail.billing.openInProvider') }}
                  </VTooltip>
                </VBtn>
              </div>
            </VCol>
            <VCol v-if="billing.subscription.provider_subscription_id" cols="12" md="6">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.providerSubscriptionId') }}
              </div>
              <div class="d-flex align-center gap-1">
                <code class="text-body-2">{{ billing.subscription.provider_subscription_id }}</code>
                <VBtn icon size="x-small" variant="text" @click="copyToClipboard(billing.subscription.provider_subscription_id)">
                  <VIcon icon="tabler-copy" size="14" />
                </VBtn>
                <VBtn
                  v-if="billing.provider_links?.subscription_url"
                  icon
                  size="x-small"
                  variant="text"
                  :href="billing.provider_links.subscription_url"
                  target="_blank"
                  tag="a"
                >
                  <VIcon icon="tabler-external-link" size="14" />
                  <VTooltip activator="parent">
                    {{ t('platformCompanyDetail.billing.openInProvider') }}
                  </VTooltip>
                </VBtn>
              </div>
            </VCol>
          </VRow>

          <!-- Subscription Actions -->
          <VDivider class="my-3" />

          <!-- Cancellation scheduled alert -->
          <VAlert
            v-if="billing.subscription.cancel_at_period_end"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-3"
          >
            {{ t('platformCompanyDetail.billing.cancellationScheduled') }}
            <template #append>
              <VBtn
                size="small"
                variant="text"
                color="warning"
                :loading="subActionLoading"
                @click="isUndoCancelDialogOpen = true"
              >
                {{ t('platformCompanyDetail.billing.undoCancel') }}
              </VBtn>
            </template>
          </VAlert>

          <!-- Suspended alert -->
          <VAlert
            v-else-if="billing.subscription.status === 'suspended'"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-3"
          >
            {{ t('platformCompanyDetail.billing.suspendedByDunning') }}
          </VAlert>

          <!-- Past due alert -->
          <VAlert
            v-else-if="billing.subscription.status === 'past_due'"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-3"
          >
            {{ t('platformCompanyDetail.billing.pastDueWarning') }}
          </VAlert>

          <!-- Action buttons -->
          <div class="d-flex gap-2 flex-wrap">
            <VBtn
              v-if="['active', 'trialing'].includes(billing.subscription.status) && !billing.subscription.cancel_at_period_end"
              size="small"
              variant="outlined"
              color="warning"
              :loading="subActionLoading"
              @click="openCancelDialog"
            >
              <VIcon icon="tabler-calendar-off" class="me-1" />
              {{ t('platformCompanyDetail.billing.scheduleCancellation') }}
            </VBtn>
            <VBtn
              v-if="billing.subscription.status === 'trialing'"
              size="small"
              variant="outlined"
              color="info"
              @click="extendTrialDialog = true"
            >
              <VIcon icon="tabler-clock-plus" class="me-1" />
              {{ t('platformCompanyDetail.billing.extendTrial') }}
            </VBtn>
          </div>
        </VCardText>
        <VCardText v-else>
          <VAlert type="info" variant="tonal" density="compact">
            {{ t('platformCompanyDetail.billing.noSubscription') }}
          </VAlert>
        </VCardText>
      </VCard>

      <!-- Dunning Alert -->
      <VAlert
        v-if="billing.dunning_invoices?.length"
        type="warning"
        variant="tonal"
        class="mb-4"
      >
        <VAlertTitle>
          {{ t('platformCompanyDetail.billing.dunningActive', { count: billing.dunning_invoices.length }) }}
        </VAlertTitle>
        <div v-for="inv in billing.dunning_invoices" :key="inv.id" class="text-body-2 mt-1">
          {{ inv.number }} — {{ formatMoney(inv.amount_due, { currency: billing.currency }) }}
          <span v-if="inv.next_retry_at" class="text-disabled">
            ({{ t('platformCompanyDetail.billing.nextRetry') }}: {{ formatDate(inv.next_retry_at) }})
          </span>
        </div>
      </VAlert>

      <!-- Last Payment Diagnostics -->
      <VCard v-if="billing.last_payment" class="mb-4" flat border>
        <VCardTitle class="d-flex align-center">
          <VIcon icon="tabler-stethoscope" class="me-2" />
          {{ t('platformCompanyDetail.billing.diagnostics') }}
        </VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.lastPaymentAmount') }}
              </div>
              <span class="text-body-1 font-weight-medium">
                {{ formatMoney(billing.last_payment.amount, { currency: billing.last_payment.currency || billing.currency }) }}
              </span>
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.lastPaymentStatus') }}
              </div>
              <StatusChip :status="billing.last_payment.status" domain="payment" size="small" />
            </VCol>
            <VCol cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.lastPaymentDate') }}
              </div>
              <span class="text-body-1">{{ formatDate(billing.last_payment.created_at) }}</span>
            </VCol>
            <VCol v-if="billing.last_payment.provider_payment_id" cols="6" md="3">
              <div class="text-body-2 text-disabled mb-1">
                {{ t('platformCompanyDetail.billing.providerPaymentId') }}
              </div>
              <div class="d-flex align-center gap-1">
                <code class="text-body-2 text-truncate" style="max-inline-size: 140px;">{{ billing.last_payment.provider_payment_id }}</code>
                <VBtn icon size="x-small" variant="text" @click="copyToClipboard(billing.last_payment.provider_payment_id)">
                  <VIcon icon="tabler-copy" size="14" />
                </VBtn>
                <VBtn
                  v-if="billing.provider_links?.payment_url"
                  icon
                  size="x-small"
                  variant="text"
                  :href="billing.provider_links.payment_url"
                  target="_blank"
                  tag="a"
                >
                  <VIcon icon="tabler-external-link" size="14" />
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- Payment Methods -->
      <VCard class="mb-4" flat border>
        <VCardTitle class="d-flex align-center">
          <VIcon icon="tabler-credit-card" class="me-2" />
          {{ t('platformCompanyDetail.billing.paymentMethods') }}
          <VChip size="x-small" class="ms-2" color="info" variant="tonal">
            {{ paymentMethods.length }}
          </VChip>
          <VSpacer />
          <VBtn
            v-if="!showAddCard"
            icon
            variant="text"
            size="small"
            color="primary"
            @click="openAddCard"
          >
            <VIcon icon="tabler-plus" size="20" />
            <VTooltip activator="parent">
              {{ t('platformCompanyDetail.billing.addPaymentMethod') }}
            </VTooltip>
          </VBtn>
        </VCardTitle>
        <VCardText v-if="pmLoading" class="text-center">
          <VProgressCircular indeterminate size="24" />
        </VCardText>
        <VCardText v-else-if="paymentMethods.length">
          <VList density="compact" class="pa-0">
            <VListItem
              v-for="pm in paymentMethods"
              :key="pm.id"
              class="px-0"
            >
              <template #prepend>
                <VIcon :icon="pmBrandIcon(pm.brand)" size="24" class="me-3" />
              </template>
              <VListItemTitle>
                {{ pm.label || pm.method_key }}
                <VChip
                  v-if="pm.is_default"
                  size="x-small"
                  color="primary"
                  variant="tonal"
                  class="ms-2"
                >
                  {{ t('platformCompanyDetail.billing.default') }}
                </VChip>
              </VListItemTitle>
              <VListItemSubtitle v-if="pm.exp_month && pm.exp_year">
                {{ t('platformCompanyDetail.billing.expires') }} {{ pm.exp_month }}/{{ pm.exp_year }}
              </VListItemSubtitle>
              <template #append>
                <VMenu>
                  <template #activator="{ props: menuProps }">
                    <VBtn
                      icon
                      variant="text"
                      size="small"
                      v-bind="menuProps"
                      :loading="pmActionLoading === pm.id"
                    >
                      <VIcon icon="tabler-dots-vertical" size="20" />
                    </VBtn>
                  </template>
                  <VList density="compact">
                    <VListItem
                      v-if="!pm.is_default"
                      @click="setDefaultPm(pm)"
                    >
                      <template #prepend>
                        <VIcon icon="tabler-star" size="18" class="me-2" />
                      </template>
                      <VListItemTitle>{{ t('platformCompanyDetail.billing.setDefault') }}</VListItemTitle>
                    </VListItem>
                    <VListItem @click="deletePm(pm)">
                      <template #prepend>
                        <VIcon icon="tabler-trash" size="18" class="me-2" color="error" />
                      </template>
                      <VListItemTitle class="text-error">
                        {{ t('common.delete') }}
                      </VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </template>
            </VListItem>
          </VList>
        </VCardText>
        <VCardText v-else-if="!showAddCard">
          <span class="text-disabled">{{ t('platformCompanyDetail.billing.noPaymentMethods') }}</span>
        </VCardText>

        <!-- Add Card Form (Stripe Elements) -->
        <VCardText v-if="showAddCard">
          <VAlert v-if="addCardError" type="error" variant="tonal" density="compact" class="mb-3">
            {{ addCardError }}
          </VAlert>
          <div
            ref="cardElementRef"
            class="pa-4 rounded border mb-3"
            style="min-block-size: 44px;"
          />
          <div class="d-flex gap-2">
            <VBtn
              color="primary"
              size="small"
              :loading="addCardLoading"
              @click="saveCard"
            >
              {{ t('platformCompanyDetail.billing.saveCard') }}
            </VBtn>
            <VBtn
              variant="text"
              size="small"
              @click="closeAddCard"
            >
              {{ t('common.cancel') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- Invoices Table -->
      <VCard class="mb-4" flat border>
        <VCardTitle class="d-flex align-center">
          <VIcon icon="tabler-file-invoice" class="me-2" />
          {{ t('platformCompanyDetail.billing.invoices') }}
          <VChip size="x-small" class="ms-2" color="info" variant="tonal">
            {{ billing.invoices?.length ?? 0 }}
          </VChip>
        </VCardTitle>
        <VDataTable
          v-if="billing.invoices?.length"
          :items="billing.invoices"
          :headers="invoiceHeaders"
          density="compact"
          :items-per-page="-1"
          hide-default-footer
        >
          <template #item.number="{ item }">
            <a
              v-if="item.provider_url"
              :href="item.provider_url"
              class="font-weight-medium text-primary text-decoration-none"
              target="_blank"
              rel="noopener"
            >
              {{ item.number || '—' }}
              <VIcon icon="tabler-external-link" size="12" class="ms-1" />
            </a>
            <span v-else class="font-weight-medium">{{ item.number || '—' }}</span>
          </template>
          <template #item.status="{ item }">
            <StatusChip :status="item.status" domain="invoice" size="x-small" />
          </template>
          <template #item.amount_due="{ item }">
            {{ formatMoney(item.amount_due, { currency: billing.currency }) }}
          </template>
          <template #item.issued_at="{ item }">
            {{ item.issued_at ? formatDate(item.issued_at) : '—' }}
          </template>
          <template #item.due_at="{ item }">
            {{ item.due_at ? formatDate(item.due_at) : '—' }}
          </template>
          <template #item.paid_at="{ item }">
            <span v-if="item.paid_at" class="text-success">{{ formatDate(item.paid_at) }}</span>
            <span v-else class="text-disabled">—</span>
          </template>
          <template #item.actions="{ item }">
            <VMenu v-if="['open', 'overdue', 'paid'].includes(item.status)">
              <template #activator="{ props: menuProps }">
                <VBtn
                  icon
                  variant="text"
                  size="x-small"
                  v-bind="menuProps"
                  :loading="invoiceActionLoading === `${item.id}-retry` || invoiceActionLoading === `${item.id}-mark-paid` || invoiceActionLoading === `${item.id}-void` || invoiceActionLoading === `${item.id}-credit-note`"
                >
                  <VIcon icon="tabler-dots-vertical" size="18" />
                </VBtn>
              </template>
              <VList density="compact">
                <VListItem
                  v-if="['open', 'overdue'].includes(item.status)"
                  @click="executeInvoiceAction('retry', item)"
                >
                  <template #prepend>
                    <VIcon icon="tabler-refresh" size="18" class="me-2" />
                  </template>
                  <VListItemTitle>{{ t('platformCompanyDetail.billing.retryPayment') }}</VListItemTitle>
                </VListItem>
                <VListItem
                  v-if="['open', 'overdue'].includes(item.status)"
                  @click="executeInvoiceAction('mark-paid', item)"
                >
                  <template #prepend>
                    <VIcon icon="tabler-check" size="18" class="me-2" />
                  </template>
                  <VListItemTitle>{{ t('platformCompanyDetail.billing.markPaidOffline') }}</VListItemTitle>
                </VListItem>
                <VListItem
                  v-if="['open', 'overdue'].includes(item.status)"
                  @click="openConfirmDialog('void', item, t('platformCompanyDetail.billing.voidInvoice'))"
                >
                  <template #prepend>
                    <VIcon icon="tabler-ban" size="18" class="me-2" color="warning" />
                  </template>
                  <VListItemTitle class="text-warning">
                    {{ t('platformCompanyDetail.billing.voidInvoice') }}
                  </VListItemTitle>
                </VListItem>
                <VListItem
                  v-if="item.status === 'paid'"
                  @click="openConfirmDialog('credit-note', item, t('platformCompanyDetail.billing.issueCreditNote'))"
                >
                  <template #prepend>
                    <VIcon icon="tabler-receipt-refund" size="18" class="me-2" color="info" />
                  </template>
                  <VListItemTitle>{{ t('platformCompanyDetail.billing.issueCreditNote') }}</VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </template>
        </VDataTable>
        <VCardText v-else>
          <span class="text-disabled">{{ t('platformCompanyDetail.billing.noInvoices') }}</span>
        </VCardText>
      </VCard>

      <!-- Wallet History -->
      <VCard flat border>
        <VCardTitle class="d-flex align-center">
          <VIcon icon="tabler-wallet" class="me-2" />
          {{ t('platformCompanyDetail.billing.walletHistory') }}
        </VCardTitle>
        <VCardText v-if="walletHistoryLoading" class="text-center">
          <VProgressCircular indeterminate size="24" />
        </VCardText>
        <VDataTable
          v-else-if="walletHistory.length"
          :items="walletHistory"
          :headers="walletHistoryHeaders"
          density="compact"
          :items-per-page="10"
        >
          <template #item.created_at="{ item }">
            {{ formatDate(item.created_at) }}
          </template>
          <template #item.type="{ item }">
            <VChip :color="walletTxColor(item.type)" size="x-small">
              {{ item.type }}
            </VChip>
          </template>
          <template #item.amount="{ item }">
            <span :class="item.type === 'credit' ? 'text-success' : 'text-error'">
              {{ item.type === 'credit' ? '+' : '-' }}{{ formatMoney(item.amount, { currency: billing.currency }) }}
            </span>
          </template>
          <template #item.balance_after="{ item }">
            {{ formatMoney(item.balance_after, { currency: billing.currency }) }}
          </template>
        </VDataTable>
        <VCardText v-else>
          <span class="text-disabled">{{ t('platformCompanyDetail.billing.noWalletHistory') }}</span>
        </VCardText>
      </VCard>
    </template>

    <!-- Confirm Dialog for destructive invoice actions -->
    <VDialog v-model="confirmDialog" max-width="400">
      <VCard>
        <VCardTitle>{{ confirmActionLabel }}</VCardTitle>
        <VCardText>
          {{ t('platformCompanyDetail.billing.confirmActionMessage', { action: confirmActionLabel, number: confirmInvoice?.number }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmDialog = false">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn color="primary" @click="executeConfirmedAction">
            {{ t('platformCompanyDetail.billing.confirmAction') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Extend Trial Dialog -->
    <VDialog v-model="extendTrialDialog" max-width="400">
      <VCard>
        <VCardTitle>
          <VIcon icon="tabler-clock-plus" class="me-2" />
          {{ t('platformCompanyDetail.billing.extendTrial') }}
        </VCardTitle>
        <VCardText>
          <AppTextField
            v-model.number="extendTrialDays"
            :label="t('platformCompanyDetail.billing.trialDays')"
            type="number"
            min="1"
            max="90"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="extendTrialDialog = false">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="subActionLoading"
            :disabled="!extendTrialDays || extendTrialDays < 1"
            @click="submitExtendTrial"
          >
            {{ t('platformCompanyDetail.billing.extendTrialConfirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Cancel Subscription Confirm Dialog (ADR-446: Billing Safety) -->
    <VDialog
      v-model="isCancelDialogOpen"
      max-width="500"
      persistent
    >
      <VCard>
        <VCardTitle class="text-h5 pa-5 d-flex align-center gap-2">
          <VIcon icon="tabler-calendar-off" color="warning" />
          {{ t('billingActions.adminCancelTitle') }}
        </VCardTitle>
        <VCardText>
          <VSkeletonLoader v-if="cancelPreviewLoading" type="text, text, text" />
          <template v-else-if="cancelPreview">
            <div class="mb-3">
              <span class="text-body-2 font-weight-medium">{{ t('billingActions.adminCancelTiming') }}</span>
              <VChip
                size="small"
                variant="tonal"
                :color="cancelPreview.timing === 'immediate' ? 'error' : 'warning'"
                class="ms-2"
              >
                {{ cancelPreview.timing === 'immediate' ? t('billingActions.adminCancelImmediate') : t('billingActions.adminCancelEndPeriod', { date: cancelPreview.period_end }) }}
              </VChip>
            </div>
            <VAlert
              v-if="cancelPreview.active_addons?.length"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-3"
            >
              <strong>{{ t('billingActions.adminCancelAddons') }}</strong>
              <ul class="mt-1">
                <li v-for="addon in cancelPreview.active_addons" :key="addon">{{ addon }}</li>
              </ul>
            </VAlert>
            <div v-if="cancelPreview.wallet_balance" class="text-body-2 text-medium-emphasis">
              {{ t('billingActions.adminCancelWallet') }} {{ formatMoney(cancelPreview.wallet_balance) }}
            </div>
          </template>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="isCancelDialogOpen = false">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="warning"
            variant="elevated"
            :loading="subActionLoading"
            @click="cancelSubscription"
          >
            {{ t('billingActions.adminCancelButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Undo Cancel Confirm Dialog (ADR-446: Billing Safety) -->
    <VDialog
      v-model="isUndoCancelDialogOpen"
      max-width="420"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-5 d-flex align-center gap-2">
          <VIcon icon="tabler-refresh" color="success" />
          {{ t('billingActions.adminUndoCancelTitle') }}
        </VCardTitle>
        <VCardText>
          <p class="mb-2">{{ t('billingActions.adminUndoCancelMessage') }}</p>
          <div v-if="billing?.subscription?.plan_key" class="text-body-2 text-medium-emphasis">
            {{ t('billingActions.adminUndoCancelPlan', { plan: billing.subscription.plan_key }) }}
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="isUndoCancelDialogOpen = false">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="success"
            variant="elevated"
            :loading="subActionLoading"
            @click="undoCancelSubscription"
          >
            {{ t('billingActions.adminUndoCancelButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
