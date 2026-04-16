<script setup>
definePage({
  meta: {
    surface: 'structure',
    module: 'core.billing',
    navActiveLink: 'company-billing-tab',
    permission: 'billing.manage',
  },
})

import { loadStripe } from '@stripe/stripe-js'
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { formatDate } from '@/utils/datetime'
import { invoiceStatusColor } from '@/utils/billing'
import { formatMoney } from '@/utils/money'
import { useAnalytics } from '@/composables/useAnalytics'

const { t } = useI18n()
const router = useRouter()
const store = useCompanyBillingStore()
const { toast } = useAppToast()
const { track } = useAnalytics()

// ── State ──
const isLoading = ref(true)
const invoices = ref([])
const selectedIds = ref([])
const walletBalance = ref(0)
const currency = ref('EUR')
const useWallet = ref(true)

// Payment flow
const step = ref('select') // 'select' | 'payment' | 'processing' | 'success'
const isCreatingIntent = ref(false)
const isConfirming = ref(false)
const paymentError = ref('')
const paidCount = ref(0)
const countdown = ref(10)
let countdownTimer = null

const startCountdown = () => {
  countdown.value = 10
  countdownTimer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) {
      clearInterval(countdownTimer)
      goToBilling()
    }
  }, 1000)
}

onBeforeUnmount(() => {
  if (countdownTimer) clearInterval(countdownTimer)
})

// Stripe
let stripe = null
let elements = null
let cardEl = null
let ibanEl = null
const cardElementRef = ref(null)
const ibanElementRef = ref(null)
const prButtonRef = ref(null)
const prAvailable = ref(false)
const clientSecret = ref(null)
const paymentIntentId = ref(null)
const intentData = ref(null)

// Saved payment methods
const savedCards = ref([])
const selectedMethod = ref('new-card') // 'new-card', 'new-sepa', or saved card provider_payment_method_id
const saveCard = ref(false)

const sepaName = ref('')

// ADR-325: Allowed payment methods from backend
const allowedPaymentMethods = computed(() => store.overview?.allowed_payment_methods ?? ['card'])
const showSepaOption = computed(() => allowedPaymentMethods.value.includes('sepa_debit'))

// ── Load outstanding invoices + saved cards ──
const load = async () => {
  isLoading.value = true
  try {
    const [outstanding] = await Promise.all([
      store.fetchOutstandingInvoices(),
      store.fetchSavedCards(),
      store.fetchOverview(),
    ])

    invoices.value = outstanding.invoices || []
    walletBalance.value = outstanding.wallet_balance || 0
    currency.value = outstanding.currency || 'EUR'
    savedCards.value = store.savedCards || []

    // ADR-336: Pre-select from store (set by invoice list/detail page) or select all
    const preSelected = store.consumePreSelectedInvoices()
    if (preSelected.length > 0) {
      selectedIds.value = invoices.value
        .filter(inv => preSelected.includes(inv.id))
        .map(inv => inv.id)
    }
    else {
      selectedIds.value = invoices.value.map(inv => inv.id)
    }

    // Default to first saved card if available
    if (savedCards.value.length > 0) {
      const defaultCard = savedCards.value.find(c => c.is_default) || savedCards.value[0]

      selectedMethod.value = defaultCard.provider_payment_method_id || defaultCard.id
    }
    else {
      selectedMethod.value = 'new-card'
    }
  }
  catch {
    toast(t('companyBilling.pay.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

onMounted(load)

// ── Computeds ──
const selectedInvoices = computed(() =>
  invoices.value.filter(inv => selectedIds.value.includes(inv.id)),
)

const selectedTotal = computed(() =>
  selectedInvoices.value.reduce((sum, inv) => sum + inv.amount_due, 0),
)

const walletApplied = computed(() => {
  if (!useWallet.value) return 0

  return Math.min(walletBalance.value, selectedTotal.value)
})

const remaining = computed(() =>
  Math.max(0, selectedTotal.value - walletApplied.value),
)

const allSelected = computed(() =>
  invoices.value.length > 0 && selectedIds.value.length === invoices.value.length,
)

const isNewMethod = computed(() => selectedMethod.value === 'new-card' || selectedMethod.value === 'new-sepa')

const selectedSavedCard = computed(() =>
  savedCards.value.find(c => (c.provider_payment_method_id || c.id) === selectedMethod.value),
)

const fmt = amount => formatMoney(amount, { currency: currency.value })

const statusLabel = status => {
  const key = `companyBilling.pay.status${status?.charAt(0).toUpperCase()}${status?.slice(1)}`

  return t(key, status)
}

const brandIcon = brand => {
  const icons = {
    visa: 'tabler-brand-visa',
    mastercard: 'tabler-brand-mastercard',
    amex: 'tabler-credit-card',
  }

  return icons[brand?.toLowerCase()] || 'tabler-credit-card'
}

const brandLabel = brand => {
  const labels = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express' }

  return labels[brand?.toLowerCase()] || (brand ? brand.charAt(0).toUpperCase() + brand.slice(1) : 'Card')
}

// ── Selection ──
const toggleAll = () => {
  if (allSelected.value) {
    selectedIds.value = []
  }
  else {
    selectedIds.value = invoices.value.map(inv => inv.id)
  }
}

const toggleInvoice = id => {
  const idx = selectedIds.value.indexOf(id)

  if (idx >= 0) {
    selectedIds.value.splice(idx, 1)
  }
  else {
    selectedIds.value.push(id)
  }
}

// ── Stripe element style (shared for card + iban) ──
const elementStyle = {
  base: {
    color: '#2F2B3D',
    fontFamily: '"Public Sans", sans-serif',
    fontSize: '15px',
    lineHeight: '24px',
    '::placeholder': { color: '#808390' },
  },
  invalid: { color: '#FF4C51' },
}

// ── Payment Flow ──
const initiatePayment = async () => {
  if (selectedIds.value.length === 0) return

  isCreatingIntent.value = true
  paymentError.value = ''

  try {
    const result = await store.createBatchPayIntent(selectedIds.value, useWallet.value)

    if (result.mode === 'wallet_paid') {
      paidCount.value = result.paid_invoice_ids?.length || selectedIds.value.length
      step.value = 'success'
      startCountdown()
      toast(t('companyBilling.pay.success', { count: paidCount.value }), 'success')
      track('invoice_paid', { invoice_ids: result.paid_invoice_ids, amount: selectedTotal.value, method: 'wallet' })

      return
    }

    // Need Stripe payment
    intentData.value = result
    clientSecret.value = result.client_secret
    paymentIntentId.value = result.payment_intent_id
    step.value = 'payment'

    // Initialize Stripe
    stripe = await loadStripe(result.publishable_key)

    // Mount individual Stripe elements
    await nextTick()
    mountStripeElements(result)
  }
  catch (err) {
    paymentError.value = err?.data?.message || err?.message || t('companyBilling.pay.failed')
  }
  finally {
    isCreatingIntent.value = false
  }
}

const mountStripeElements = async result => {
  if (!stripe) return

  elements = stripe.elements({
    fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700' }],
  })

  // Payment Request Button — Apple Pay / Google Pay (native browser UI)
  const paymentRequest = stripe.paymentRequest({
    country: 'FR',
    currency: result.currency?.toLowerCase() || 'eur',
    total: {
      label: t('companyBilling.pay.title'),
      amount: result.remaining || 0,
    },
  })

  paymentRequest.canMakePayment().then(canPay => {
    if (!canPay || !prButtonRef.value) return

    prAvailable.value = true

    const prBtn = elements.create('paymentRequestButton', {
      paymentRequest,
      style: { paymentRequestButton: { type: 'default', theme: 'dark', height: '44px' } },
    })

    prBtn.mount(prButtonRef.value)
  })

  // Apple Pay / Google Pay payment confirmation callback
  paymentRequest.on('paymentmethod', async ev => {
    isConfirming.value = true
    paymentError.value = ''

    const { error, paymentIntent } = await stripe.confirmCardPayment(
      result.client_secret,
      { payment_method: ev.paymentMethod.id },
      { handleActions: false },
    )

    if (error) {
      ev.complete('fail')
      paymentError.value = error.message
      isConfirming.value = false

      return
    }

    ev.complete('success')

    // Handle 3DS if needed
    if (paymentIntent.status === 'requires_action') {
      const { error: actionError } = await stripe.confirmCardPayment(result.client_secret)

      if (actionError) {
        paymentError.value = actionError.message
        isConfirming.value = false

        return
      }
    }

    // Confirm with backend
    try {
      const backendResult = await store.confirmBatchPayment(paymentIntentId.value, false)

      if (backendResult.mode === 'processing') {
        step.value = 'processing'
      }
      else {
        paidCount.value = backendResult.paid_invoice_ids?.length || 0
        step.value = 'success'
        startCountdown()
        toast(t('companyBilling.pay.success', { count: paidCount.value }), 'success')
        track('invoice_paid', { invoice_ids: backendResult.paid_invoice_ids, amount: selectedTotal.value, method: 'apple_google_pay' })
      }
    }
    catch (err) {
      paymentError.value = err?.data?.message || err?.message || t('companyBilling.pay.failed')
    }
    finally {
      isConfirming.value = false
    }
  })

  // Mount card/iban if a new method is already selected
  await nextTick()
  mountActiveStripeElement()
}

const mountActiveStripeElement = () => {
  if (!elements) return

  if (selectedMethod.value === 'new-card' && cardElementRef.value && !cardEl) {
    cardEl = elements.create('card', { style: elementStyle, hidePostalCode: true })
    cardEl.mount(cardElementRef.value)
  }
  else if (selectedMethod.value === 'new-sepa' && ibanElementRef.value && !ibanEl) {
    ibanEl = elements.create('iban', { style: elementStyle, supportedCountries: ['SEPA'] })
    ibanEl.mount(ibanElementRef.value)
  }
}

// Watch for payment method switch — mount/unmount Stripe elements
watch(selectedMethod, async val => {
  if (!stripe || !elements) return

  if (cardEl) {
    cardEl.destroy()
    cardEl = null
  }
  if (ibanEl) {
    ibanEl.destroy()
    ibanEl = null
  }

  await nextTick()
  mountActiveStripeElement()
})

const confirmPayment = async () => {
  if (!stripe) return

  isConfirming.value = true
  paymentError.value = ''

  try {
    let error, paymentIntent

    if (selectedMethod.value === 'new-sepa') {
      // New SEPA via IbanElement
      if (!ibanEl) return
      if (!sepaName.value.trim()) {
        paymentError.value = t('companyBilling.pay.sepaNameRequired')
        isConfirming.value = false

        return
      }
      ;({ error, paymentIntent } = await stripe.confirmSepaDebitPayment(clientSecret.value, {
        payment_method: {
          sepa_debit: ibanEl,
          billing_details: {
            name: sepaName.value.trim(),
          },
        },
      }))
    }
    else if (selectedMethod.value === 'new-card') {
      // New card via CardElement
      if (!cardEl) return
      ;({ error, paymentIntent } = await stripe.confirmCardPayment(clientSecret.value, {
        payment_method: { card: cardEl },
      }))
    }
    else if (selectedSavedCard.value?.method_key === 'sepa_debit') {
      // Saved SEPA
      ;({ error, paymentIntent } = await stripe.confirmSepaDebitPayment(clientSecret.value, {
        payment_method: selectedMethod.value,
      }))
    }
    else {
      // Saved card
      ;({ error, paymentIntent } = await stripe.confirmCardPayment(clientSecret.value, {
        payment_method: selectedMethod.value,
      }))
    }

    if (error) {
      paymentError.value = error.message
      isConfirming.value = false

      return
    }

    // Tell the backend to distribute (or acknowledge processing)
    const result = await store.confirmBatchPayment(
      paymentIntentId.value,
      isNewMethod.value && saveCard.value,
    )

    if (result.mode === 'processing') {
      step.value = 'processing'
    }
    else {
      paidCount.value = result.paid_invoice_ids?.length || 0
      step.value = 'success'
      startCountdown()
      toast(t('companyBilling.pay.success', { count: paidCount.value }), 'success')

      const method = selectedMethod.value === 'new-card' ? 'card' : selectedMethod.value === 'new-sepa' ? 'sepa' : 'saved_card'
      track('invoice_paid', { invoice_ids: result.paid_invoice_ids, amount: selectedTotal.value, method })
    }
  }
  catch (err) {
    paymentError.value = err?.data?.message || err?.message || t('companyBilling.pay.failed')
  }
  finally {
    isConfirming.value = false
  }
}

const goBack = () => {
  if (step.value === 'payment') {
    step.value = 'select'
    clientSecret.value = null
    paymentIntentId.value = null
    intentData.value = null
    if (cardEl) { cardEl.destroy(); cardEl = null }
    if (ibanEl) { ibanEl.destroy(); ibanEl = null }
    elements = null
    prAvailable.value = false
  }
  else {
    router.push('/company/billing/overview')
  }
}

const goToBilling = () => {
  router.push('/company/billing/overview')
}
</script>

<template>
  <div>
    <!-- Loading -->
    <VSkeletonLoader
      v-if="isLoading"
      type="card, card"
    />

    <!-- No outstanding invoices -->
    <VCard
      v-else-if="invoices.length === 0"
      class="text-center pa-8"
    >
      <VIcon
        icon="tabler-check"
        size="64"
        color="success"
        class="mb-4"
      />
      <h5 class="text-h5 mb-2">
        {{ t('companyBilling.pay.noOutstanding') }}
      </h5>
      <VBtn
        variant="tonal"
        color="primary"
        @click="goToBilling"
      >
        {{ t('companyBilling.pay.backToBilling') }}
      </VBtn>
    </VCard>

    <!-- Success -->
    <VCard
      v-else-if="step === 'success'"
      class="text-center pa-8"
    >
      <VIcon
        icon="tabler-circle-check"
        size="64"
        color="success"
        class="mb-4"
      />
      <h5 class="text-h5 mb-2">
        {{ t('companyBilling.pay.successTitle') }}
      </h5>
      <p class="text-body-1 mb-2">
        {{ t('companyBilling.pay.success', { count: paidCount }) }}
      </p>
      <p class="text-body-2 text-disabled mb-4">
        {{ t('companyBilling.pay.redirectCountdown', { seconds: countdown }) }}
      </p>
      <VBtn
        color="primary"
        @click="goToBilling"
      >
        {{ t('companyBilling.pay.backToBilling') }}
      </VBtn>
    </VCard>

    <!-- Processing (SEPA / async) -->
    <VCard
      v-else-if="step === 'processing'"
      class="text-center pa-8"
    >
      <VIcon
        icon="tabler-clock-check"
        size="64"
        color="info"
        class="mb-4"
      />
      <h5 class="text-h5 mb-2">
        {{ t('companyBilling.pay.processingTitle') }}
      </h5>
      <p class="text-body-1 mb-4">
        {{ t('companyBilling.pay.processingMessage') }}
      </p>
      <VBtn
        color="primary"
        @click="goToBilling"
      >
        {{ t('companyBilling.pay.backToBilling') }}
      </VBtn>
    </VCard>

    <!-- Payment Flow -->
    <template v-else>
      <VRow>
        <!-- Left column -->
        <VCol
          cols="12"
          md="8"
        >
          <!-- Step 1: Invoice Selection -->
          <VCard v-if="step === 'select'">
            <VCardTitle class="d-flex align-center justify-space-between">
              <div class="d-flex align-center gap-2">
                <VIcon icon="tabler-file-invoice" />
                {{ t('companyBilling.pay.title') }}
              </div>
              <VChip
                color="primary"
                size="small"
              >
                {{ t('companyBilling.pay.invoicesSelected', selectedIds.length) }}
              </VChip>
            </VCardTitle>

            <VDivider />

            <VAlert
              v-if="paymentError"
              type="error"
              variant="tonal"
              class="ma-4"
              closable
              @click:close="paymentError = ''"
            >
              {{ paymentError }}
            </VAlert>

            <VTable density="comfortable">
              <thead>
                <tr>
                  <th style="width: 40px">
                    <VCheckbox
                      :model-value="allSelected"
                      hide-details
                      @update:model-value="toggleAll"
                    />
                  </th>
                  <th>{{ t('companyBilling.pay.colNumber') }}</th>
                  <th>{{ t('companyBilling.pay.colStatus') }}</th>
                  <th>{{ t('companyBilling.pay.colDueDate') }}</th>
                  <th class="text-end">
                    {{ t('companyBilling.pay.colAmount') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="inv in invoices"
                  :key="inv.id"
                  class="cursor-pointer"
                  @click="toggleInvoice(inv.id)"
                >
                  <td>
                    <VCheckbox
                      :model-value="selectedIds.includes(inv.id)"
                      hide-details
                      @click.stop
                      @update:model-value="toggleInvoice(inv.id)"
                    />
                  </td>
                  <td class="font-weight-medium">
                    {{ inv.number }}
                  </td>
                  <td>
                    <VChip
                      :color="invoiceStatusColor(inv.status)"
                      size="x-small"
                    >
                      {{ statusLabel(inv.status) }}
                    </VChip>
                  </td>
                  <td class="text-body-2">
                    {{ formatDate(inv.due_at) }}
                  </td>
                  <td class="text-end font-weight-medium">
                    {{ fmt(inv.amount_due) }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>

          <!-- Step 2: Payment Method Selection -->
          <VCard v-if="step === 'payment'">
            <VCardTitle class="d-flex align-center gap-2">
              <VBtn
                icon
                variant="text"
                size="small"
                @click="goBack"
              >
                <VIcon icon="tabler-arrow-left" />
              </VBtn>
              {{ t('companyBilling.pay.paymentMethod') }}
            </VCardTitle>

            <VDivider />

            <VCardText>
              <VAlert
                v-if="paymentError"
                type="error"
                variant="tonal"
                class="mb-4"
                closable
                @click:close="paymentError = ''"
              >
                {{ paymentError }}
              </VAlert>

              <VRadioGroup
                v-model="selectedMethod"
                hide-details
              >
                <!-- Saved payment methods -->
                <template v-if="savedCards.length > 0">
                <p class="text-body-2 font-weight-medium mb-3">
                  {{ t('companyBilling.pay.savedMethods') }}
                </p>

                <div class="d-flex flex-column gap-3 mb-6">
                  <VLabel
                    v-for="card in savedCards"
                    :key="card.id"
                    class="custom-input custom-radio rounded cursor-pointer"
                    :class="selectedMethod === (card.provider_payment_method_id || card.id) ? 'active' : ''"
                  >
                    <div>
                      <VRadio :value="card.provider_payment_method_id || card.id" />
                    </div>
                    <div class="flex-grow-1 d-flex align-center gap-3">
                      <VIcon
                        :icon="card.method_key === 'sepa_debit' ? 'tabler-building-bank' : brandIcon(card.brand)"
                        size="24"
                      />

                      <div class="flex-grow-1">
                        <span class="font-weight-medium">
                          <template v-if="card.method_key === 'card'">
                            {{ brandLabel(card.brand) }} •••• {{ card.last4 }}
                          </template>
                          <template v-else>
                            SEPA •••• {{ card.last4 }}
                            <span
                              v-if="card.bank_name"
                              class="text-body-2 text-disabled ms-2"
                            >
                              {{ card.bank_name }}
                            </span>
                          </template>
                        </span>
                        <span
                          v-if="card.exp_month && card.exp_year"
                          class="text-body-2 text-disabled ms-2"
                        >
                          {{ t('companyBilling.pay.cardExpiry', { month: String(card.exp_month).padStart(2, '0'), year: card.exp_year }) }}
                        </span>
                      </div>

                      <VChip
                        v-if="card.is_default"
                        size="x-small"
                        color="success"
                        variant="tonal"
                      >
                        {{ t('companyBilling.pay.default') }}
                      </VChip>
                    </div>
                  </VLabel>
                </div>
              </template>

              <!-- New payment methods -->
              <p class="text-body-2 font-weight-medium mb-3">
                {{ t('companyBilling.pay.newMethod') }}
              </p>

              <!-- Apple Pay / Google Pay (native browser button) -->
              <div
                ref="prButtonRef"
                class="mb-3"
              />

              <div
                v-if="prAvailable"
                class="d-flex align-center gap-3 mb-3"
              >
                <VDivider />
                <span class="text-body-2 text-disabled text-no-wrap">{{ t('companyBilling.pay.or') }}</span>
                <VDivider />
              </div>

              <div class="d-flex flex-column gap-3">
                <!-- New Card -->
                <VLabel
                  class="custom-input custom-radio rounded cursor-pointer"
                  :class="selectedMethod === 'new-card' ? 'active' : ''"
                >
                  <div>
                    <VRadio value="new-card" />
                  </div>
                  <div class="flex-grow-1">
                    <div class="d-flex align-center gap-x-4">
                      <VIcon
                        icon="tabler-credit-card"
                        size="24"
                      />
                      <span class="font-weight-medium">{{ t('companyBilling.pay.card') }}</span>
                    </div>
                    <div
                      v-if="selectedMethod === 'new-card'"
                      class="mt-4"
                      @click.stop
                    >
                      <div
                        ref="cardElementRef"
                        class="stripe-element-container"
                      />
                    </div>
                  </div>
                </VLabel>

                <!-- New SEPA — ADR-325: only shown if SEPA allowed by policy -->
                <VLabel
                  v-if="showSepaOption"
                  class="custom-input custom-radio rounded cursor-pointer"
                  :class="selectedMethod === 'new-sepa' ? 'active' : ''"
                >
                  <div>
                    <VRadio value="new-sepa" />
                  </div>
                  <div class="flex-grow-1">
                    <div class="d-flex align-center gap-x-4">
                      <VIcon
                        icon="tabler-building-bank"
                        size="24"
                      />
                      <span class="font-weight-medium">{{ t('companyBilling.pay.sepa') }}</span>
                    </div>

                    <div
                      v-if="selectedMethod === 'new-sepa'"
                      class="mt-4"
                      @click.stop
                    >
                      <AppTextField
                        v-model="sepaName"
                        :label="t('companyBilling.pay.holderName')"
                        :placeholder="t('companyBilling.pay.holderNamePlaceholder')"
                        class="mb-3"
                      />

                      <div
                        ref="ibanElementRef"
                        class="stripe-element-container"
                      />

                      <p
                        class="text-body-2 text-disabled mt-3"
                        style="font-size: 12px; line-height: 1.4;"
                      >
                        {{ t('companyBilling.pay.sepaMandate') }}
                      </p>
                    </div>
                  </div>
                </VLabel>
              </div>

              <!-- Save for future payments -->
              <VCheckbox
                v-if="isNewMethod"
                v-model="saveCard"
                :label="t('companyBilling.pay.saveForFuture')"
                hide-details
                class="mt-4"
              />
              </VRadioGroup>

              <!-- Pay button -->
              <VBtn
                v-can="'billing.manage'"
                block
                color="primary"
                size="large"
                class="mt-4"
                :loading="isConfirming"
                :disabled="isConfirming"
                @click="confirmPayment"
              >
                {{ t('companyBilling.pay.payButton', { amount: fmt(intentData?.remaining || 0) }) }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Right: Summary -->
        <VCol
          cols="12"
          md="4"
        >
          <VCard>
            <VCardTitle>
              {{ t('companyBilling.pay.paymentSummary') }}
            </VCardTitle>

            <VDivider />

            <VCardText>
              <div class="d-flex justify-space-between mb-2">
                <span class="text-body-2">{{ t('companyBilling.pay.invoicesSelected', selectedIds.length) }}</span>
              </div>

              <div class="d-flex justify-space-between mb-2">
                <span class="text-body-2">{{ t('companyBilling.pay.total') }}</span>
                <span class="font-weight-medium">{{ fmt(selectedTotal) }}</span>
              </div>

              <template v-if="useWallet && walletApplied > 0">
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-body-2">{{ t('companyBilling.pay.walletCredit') }}</span>
                  <span class="font-weight-medium text-success">-{{ fmt(walletApplied) }}</span>
                </div>
              </template>

              <VDivider class="my-3" />

              <div class="d-flex justify-space-between mb-4">
                <span class="font-weight-bold">{{ t('companyBilling.pay.amountToPay') }}</span>
                <span class="font-weight-bold text-primary">{{ fmt(remaining) }}</span>
              </div>

              <!-- Wallet toggle -->
              <template v-if="walletBalance > 0">
                <VCheckbox
                  v-model="useWallet"
                  :label="t('companyBilling.pay.useWallet', { amount: fmt(walletBalance) })"
                  hide-details
                  class="mb-4"
                />
              </template>

              <!-- Pay button (step=select) -->
              <VBtn
                v-if="step === 'select'"
                v-can="'billing.manage'"
                block
                color="primary"
                size="large"
                :disabled="selectedIds.length === 0 || isCreatingIntent"
                :loading="isCreatingIntent"
                @click="initiatePayment"
              >
                <template v-if="remaining > 0">
                  {{ t('companyBilling.pay.continueToPayment') }}
                </template>
                <template v-else>
                  {{ t('companyBilling.pay.payWithWallet') }}
                </template>
              </VBtn>

              <VBtn
                block
                variant="tonal"
                color="secondary"
                class="mt-3"
                @click="goBack"
              >
                {{ t('companyBilling.pay.backToBilling') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style lang="scss" scoped>
.stripe-element-container {
  padding: 12px 14px;
  border: 1px solid rgba(47, 43, 61, 0.12);
  border-radius: 6px;
  min-block-size: 44px;
}

.custom-radio {
  display: flex;
  align-items: flex-start;
  gap: 0.25rem;

  .v-radio {
    margin-block-start: -0.45rem;
  }
}
</style>
