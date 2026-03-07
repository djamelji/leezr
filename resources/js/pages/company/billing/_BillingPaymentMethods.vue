<script setup>
import { loadStripe } from '@stripe/stripe-js'
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'

const { t } = useI18n()
const store = useCompanyBillingStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const showMethodPicker = ref(false)
const showCardForm = ref(false)
const showSepaForm = ref(false)
const selectedMethod = ref('card')
const cardError = ref('')
const isSaving = ref(false)

// Card form
let stripe = null
let cardElement = null
const cardElementRef = ref(null)

// SEPA form
let ibanElement = null
const ibanElementRef = ref(null)
const sepaName = ref('')
const sepaEmail = ref('')

// Delete confirmation
const deletingCard = ref(null)

onMounted(async () => {
  try {
    await store.fetchSavedCards()
  }
  finally {
    isLoading.value = false
  }
})

// ── Visual helpers ──

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

const fundingLabel = funding => {
  const labels = { credit: 'Credit', debit: 'Debit', prepaid: 'Prepaid' }

  return labels[funding] || ''
}

// ── Add Payment Method ──

const showAddPicker = () => {
  showMethodPicker.value = true
  showCardForm.value = false
  showSepaForm.value = false
  cardError.value = ''
}

const openForm = async method => {
  selectedMethod.value = method
  showMethodPicker.value = false
  cardError.value = ''

  try {
    const data = await store.createSetupIntent(method)

    if (!data?.publishable_key) {
      cardError.value = t('companyBilling.stripeNotConfigured')

      return
    }

    if (!stripe)
      stripe = await loadStripe(data.publishable_key)

    const elements = stripe.elements()

    await nextTick()

    if (method === 'sepa_debit') {
      showSepaForm.value = true
      showCardForm.value = false
      await nextTick()
      ibanElement = elements.create('iban', { supportedCountries: ['SEPA'] })
      ibanElement.mount(ibanElementRef.value)
    }
    else {
      showCardForm.value = true
      showSepaForm.value = false
      await nextTick()
      cardElement = elements.create('card', {
        hidePostalCode: true,
        style: {
          base: {
            fontSize: '16px',
            color: '#424242',
            '::placeholder': { color: '#aab7c4' },
          },
        },
      })
      cardElement.mount(cardElementRef.value)
    }
  }
  catch (e) {
    cardError.value = e?.data?.message || e?.response?._data?.message || e?.message || t('companyBilling.setupIntentFailed')
  }
}

// ── Save Card ──

const saveCard = async () => {
  if (!stripe || !cardElement || !store.setupIntent?.client_secret)
    return

  isSaving.value = true
  cardError.value = ''

  try {
    const { error, setupIntent } = await stripe.confirmCardSetup(store.setupIntent.client_secret, {
      payment_method: { card: cardElement },
    })

    if (error) {
      cardError.value = error.message

      return
    }

    // Confirm synchronously — creates profile without waiting for webhook
    const result = await store.confirmSetupIntent(setupIntent.payment_method)

    closeForm()

    if (result.duplicate)
      toast(t('companyBilling.cardAlreadyExists'), 'info')
    else
      toast(t('companyBilling.cardSaved'), 'success')
  }
  catch (e) {
    cardError.value = e?.data?.message || e?.message || t('companyBilling.setupIntentFailed')
  }
  finally {
    isSaving.value = false
  }
}

// ── Save SEPA ──

const saveSepa = async () => {
  if (!stripe || !ibanElement || !store.setupIntent?.client_secret)
    return

  if (!sepaName.value.trim() || !sepaEmail.value.trim()) {
    cardError.value = t('companyBilling.sepaFieldsRequired')

    return
  }

  isSaving.value = true
  cardError.value = ''

  try {
    const { error, setupIntent } = await stripe.confirmSepaDebitSetup(store.setupIntent.client_secret, {
      payment_method: {
        sepa_debit: ibanElement,
        billing_details: {
          name: sepaName.value,
          email: sepaEmail.value,
        },
      },
    })

    if (error) {
      cardError.value = error.message

      return
    }

    // Confirm synchronously — creates profile without waiting for webhook
    const result = await store.confirmSetupIntent(setupIntent.payment_method)

    closeForm()

    if (result.duplicate)
      toast(t('companyBilling.cardAlreadyExists'), 'info')
    else
      toast(t('companyBilling.ibanSaved'), 'success')
  }
  catch (e) {
    cardError.value = e?.data?.message || e?.message || t('companyBilling.setupIntentFailed')
  }
  finally {
    isSaving.value = false
  }
}

// ── Close Form ──

const closeForm = () => {
  showMethodPicker.value = false
  showCardForm.value = false
  showSepaForm.value = false
  cardError.value = ''
  sepaName.value = ''
  sepaEmail.value = ''

  if (cardElement) {
    cardElement.unmount()
    cardElement = null
  }
  if (ibanElement) {
    ibanElement.unmount()
    ibanElement = null
  }
}

// ── Set Default ──

const setDefault = async card => {
  try {
    await store.setDefaultCard(card.id)
    toast(t('companyBilling.defaultCardUpdated'), 'success')
  }
  catch {
    toast(t('companyBilling.defaultCardFailed'), 'error')
  }
}

// ── Delete ──

const confirmDelete = card => { deletingCard.value = card }
const cancelDelete = () => { deletingCard.value = null }

const executeDelete = async () => {
  try {
    await store.deleteSavedCard(deletingCard.value.id)
    toast(t('companyBilling.cardDeleted'), 'success')
  }
  catch {
    toast(t('companyBilling.cardDeleteFailed'), 'error')
  }
  finally {
    deletingCard.value = null
  }
}
</script>

<template>
  <div>
    <VSkeletonLoader
      v-if="isLoading"
      type="card"
    />

    <template v-else>
      <!-- Empty state -->
      <VCard
        v-if="store.savedCards.length === 0 && !showMethodPicker && !showCardForm && !showSepaForm"
        class="text-center pa-6 text-disabled"
      >
        <VCardText>
          <VIcon
            icon="tabler-credit-card-off"
            size="48"
            class="mb-2"
          />
          <p class="text-body-1">
            {{ t('companyBilling.noPaymentMethods') }}
          </p>
          <VBtn
            variant="tonal"
            color="primary"
            prepend-icon="tabler-plus"
            @click="showAddPicker"
          >
            {{ t('companyBilling.addPaymentMethod') }}
          </VBtn>
        </VCardText>
      </VCard>

      <!-- ═══ Payment Methods Grid ═══ -->
      <VRow v-if="store.savedCards.length > 0">
        <VCol
          v-for="pm in store.savedCards"
          :key="pm.id"
          cols="12"
          sm="6"
        >
          <VCard
            class="h-100"
            :style="pm.is_default ? 'border-color: rgb(var(--v-theme-primary))' : ''"
          >
            <VCardItem>
              <template #prepend>
                <VIcon
                  :icon="pm.method_key === 'sepa_debit' ? 'tabler-building-bank' : brandIcon(pm.brand)"
                  size="28"
                  class="me-2"
                />
              </template>

              <VCardTitle class="text-body-1 font-weight-medium">
                <template v-if="pm.method_key === 'card'">
                  {{ brandLabel(pm.brand) }}
                </template>
                <template v-else>
                  {{ pm.holder_name || t('companyBilling.sepaAccount') }}
                </template>
              </VCardTitle>

              <template #append>
                <div class="d-flex align-center gap-1">
                  <VChip
                    v-if="pm.is_default"
                    size="x-small"
                    color="success"
                    variant="tonal"
                  >
                    {{ t('companyBilling.defaultCard') }}
                  </VChip>
                  <IconBtn
                    v-if="!pm.is_default"
                    size="small"
                    :title="t('companyBilling.setAsDefault')"
                    @click="setDefault(pm)"
                  >
                    <VIcon
                      icon="tabler-star"
                      size="18"
                    />
                  </IconBtn>
                  <IconBtn
                    size="small"
                    color="error"
                    :title="t('common.delete')"
                    @click="confirmDelete(pm)"
                  >
                    <VIcon
                      icon="tabler-trash"
                      size="18"
                    />
                  </IconBtn>
                </div>
              </template>
            </VCardItem>

            <VCardText class="pt-0">
              <!-- Card details -->
              <template v-if="pm.method_key === 'card'">
                <div class="d-flex align-center gap-2 mb-2">
                  <span
                    class="text-body-2"
                    style="font-family: 'Courier New', monospace; letter-spacing: 1px;"
                  >
                    •••• •••• •••• {{ pm.last4 }}
                  </span>
                  <VChip
                    v-if="pm.funding"
                    size="x-small"
                    variant="tonal"
                    :color="pm.funding === 'credit' ? 'primary' : 'secondary'"
                  >
                    {{ fundingLabel(pm.funding) }}
                  </VChip>
                </div>
                <div class="text-body-2 text-disabled">
                  {{ t('companyBilling.cardExpiry', { month: String(pm.exp_month).padStart(2, '0'), year: pm.exp_year }) }}
                  <template v-if="pm.country">
                    <span class="mx-1">·</span>
                    {{ pm.country }}
                  </template>
                </div>
              </template>

              <!-- SEPA details -->
              <template v-else>
                <div
                  class="text-body-2 mb-3"
                  style="font-family: 'Courier New', monospace; letter-spacing: 1px;"
                >
                  {{ pm.country }}** **** **** **** **{{ pm.last4 }}
                </div>
                <div class="d-flex gap-6">
                  <div>
                    <div class="text-caption text-disabled text-uppercase">
                      {{ t('companyBilling.domiciliation') }}
                    </div>
                    <div class="text-body-2 font-weight-medium">
                      {{ pm.bank_name || pm.bank_code || '—' }}
                    </div>
                  </div>
                  <div>
                    <div class="text-caption text-disabled text-uppercase">
                      BIC
                    </div>
                    <div class="text-body-2 font-weight-medium">
                      {{ pm.bank_code || '—' }}
                    </div>
                  </div>
                  <div>
                    <div class="text-caption text-disabled text-uppercase">
                      {{ t('companyBilling.ibanCountry') }}
                    </div>
                    <div class="text-body-2 font-weight-medium">
                      {{ pm.country || '—' }}
                    </div>
                  </div>
                </div>
              </template>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Add button -->
        <VCol
          v-if="!showMethodPicker && !showCardForm && !showSepaForm"
          cols="12"
          class="d-flex justify-end"
        >
          <VBtn
            size="small"
            variant="tonal"
            color="primary"
            prepend-icon="tabler-plus"
            @click="showAddPicker"
          >
            {{ t('companyBilling.addPaymentMethod') }}
          </VBtn>
        </VCol>
      </VRow>

      <!-- Fallback info -->
      <VAlert
        v-if="store.savedCards.length > 1"
        type="info"
        variant="tonal"
        icon="tabler-info-circle"
        density="compact"
        class="mt-3"
      >
        {{ t('companyBilling.fallbackInfo') }}
      </VAlert>

      <!-- Method type picker -->
      <VCard
        v-if="showMethodPicker"
        class="mt-4"
      >
        <VCardText>
          <p class="text-body-2 mb-3">
            {{ t('companyBilling.choosePaymentType') }}
          </p>
          <div class="d-flex gap-2">
            <VBtn
              variant="outlined"
              :color="selectedMethod === 'card' ? 'primary' : 'secondary'"
              prepend-icon="tabler-credit-card"
              @click="openForm('card')"
            >
              {{ t('companyBilling.addCardBtn') }}
            </VBtn>
            <VBtn
              variant="outlined"
              :color="selectedMethod === 'sepa_debit' ? 'primary' : 'secondary'"
              prepend-icon="tabler-building-bank"
              @click="openForm('sepa_debit')"
            >
              {{ t('companyBilling.addIbanBtn') }}
            </VBtn>
          </div>
          <VBtn
            variant="text"
            size="small"
            class="mt-2"
            @click="closeForm"
          >
            {{ t('common.cancel') }}
          </VBtn>
        </VCardText>
      </VCard>

      <!-- Error alert -->
      <VAlert
        v-if="cardError"
        type="error"
        variant="tonal"
        class="mt-4 mb-4"
      >
        {{ cardError }}
      </VAlert>

      <!-- Card form -->
      <VCard
        v-if="showCardForm"
        class="mt-4"
      >
        <VCardText>
          <div
            ref="cardElementRef"
            class="pa-4 rounded border"
            style="min-block-size: 44px;"
          />

          <div class="d-flex gap-2 mt-4">
            <VBtn
              color="primary"
              :loading="isSaving"
              @click="saveCard"
            >
              {{ t('companyBilling.saveCard') }}
            </VBtn>
            <VBtn
              variant="tonal"
              @click="closeForm"
            >
              {{ t('common.cancel') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- SEPA form -->
      <VCard
        v-if="showSepaForm"
        class="mt-4"
      >
        <VCardText>
          <AppTextField
            v-model="sepaName"
            :label="t('companyBilling.accountHolder')"
            class="mb-3"
          />
          <AppTextField
            v-model="sepaEmail"
            :label="t('companyBilling.email')"
            type="email"
            class="mb-3"
          />
          <div
            ref="ibanElementRef"
            class="pa-4 rounded border mb-3"
            style="min-block-size: 44px;"
          />
          <p class="text-body-2 text-disabled mb-3">
            {{ t('companyBilling.sepaMandate') }}
          </p>

          <div class="d-flex gap-2">
            <VBtn
              color="primary"
              :loading="isSaving"
              @click="saveSepa"
            >
              {{ t('companyBilling.saveIban') }}
            </VBtn>
            <VBtn
              variant="tonal"
              @click="closeForm"
            >
              {{ t('common.cancel') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </template>
  </div>

  <!-- Delete confirmation dialog -->
  <VDialog
    :model-value="!!deletingCard"
    max-width="400"
    @update:model-value="!$event && cancelDelete()"
  >
    <VCard>
      <VCardTitle>{{ t('companyBilling.confirmDeleteCard') }}</VCardTitle>
      <VCardText>{{ t('companyBilling.confirmDeleteCardDesc') }}</VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="tonal"
          @click="cancelDelete"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          @click="executeDelete"
        >
          {{ t('common.delete') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
