<script setup>
import { loadStripe } from '@stripe/stripe-js'
import { useTheme } from 'vuetify'
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { useAuthStore } from '@/core/stores/auth'
import EmptyState from '@/core/components/EmptyState.vue'
import TrustBadges from '@/core/components/TrustBadges.vue'

const { t } = useI18n()
const store = useCompanyBillingStore()
const authStore = useAuthStore()
const { toast } = useAppToast()
const vuetifyTheme = useTheme()

const isDark = computed(() => vuetifyTheme.current.value.dark)
const appName = import.meta.env.VITE_APP_NAME || 'Leezr'

const isLoading = ref(true)
const loadError = ref(false)
const showMethodPicker = ref(false)
const showCardForm = ref(false)
const showSepaForm = ref(false)
const selectedMethod = ref('card')
const cardError = ref('')
const isSaving = ref(false)
const actionLoading = ref(null)
const sepaStep = ref(1)

// Card form
let stripe = null
let cardElement = null
const cardElementRef = ref(null)

// SEPA form
let ibanElement = null
const ibanElementRef = ref(null)
const sepaName = ref('')
const mandateAccepted = ref(false)
const sepaPreferredDay = ref(null)

// ADR-328 S3: Preferred debit day options (per IBAN)
const debitDayItems = [
  { title: t('companyBilling.debitOnInvoice'), value: null },
  { title: t('companyBilling.dayOfMonth', { day: 1 }), value: 1 },
  { title: t('companyBilling.dayOfMonth', { day: 5 }), value: 5 },
  { title: t('companyBilling.dayOfMonth', { day: 10 }), value: 10 },
  { title: t('companyBilling.dayOfMonth', { day: 15 }), value: 15 },
  { title: t('companyBilling.dayOfMonth', { day: 20 }), value: 20 },
  { title: t('companyBilling.dayOfMonth', { day: 25 }), value: 25 },
]

const debitDayLoading = ref(null)

const updateDebitDay = async (profileId, day) => {
  debitDayLoading.value = profileId
  try {
    await store.setDebitDay(profileId, day)
    toast(t('companyBilling.debitDayUpdated'), 'success')
  }
  catch {
    toast(t('companyBilling.debitDayFailed'), 'error')
  }
  finally {
    debitDayLoading.value = null
  }
}

// ADR-325: Allowed payment methods from backend
const allowedPaymentMethods = computed(() => store.overview?.allowed_payment_methods ?? ['card'])
const showSepaOption = computed(() => allowedPaymentMethods.value.includes('sepa_debit'))

// Delete confirmation
const deletingCard = ref(null)

const fetchMethods = async () => {
  isLoading.value = true
  loadError.value = false
  try {
    await Promise.all([
      store.fetchSavedCards(),
      store.overview ? Promise.resolve() : store.fetchOverview(),
    ])
  }
  catch {
    loadError.value = true
  }
  finally {
    isLoading.value = false
  }
}

onMounted(fetchMethods)

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
  if (store.savedCards.length >= store.maxPaymentMethods) {
    toast(t('companyBilling.maxMethodsReached'), 'warning')

    return
  }
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
      // Pré-remplir avec le nom de l'entreprise, modifiable par l'utilisateur
      if (!sepaName.value) {
        sepaName.value = authStore.currentCompany?.name || ''
      }
      showSepaForm.value = true
      showCardForm.value = false
      await nextTick()
      ibanElement = elements.create('iban', {
        supportedCountries: ['SEPA'],
        style: {
          base: {
            fontSize: '16px',
            color: isDark.value ? '#E7E3FCDE' : '#424242',
            '::placeholder': { color: isDark.value ? '#E7E3FC61' : '#aab7c4' },
          },
          invalid: { color: '#FF4C51' },
        },
      })
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
            color: isDark.value ? '#E7E3FCDE' : '#424242',
            '::placeholder': { color: isDark.value ? '#E7E3FC61' : '#aab7c4' },
          },
          invalid: { color: '#FF4C51' },
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
  if (!stripe || !cardElement)
    return

  // Recreate setup intent if missing or consumed
  if (!store.setupIntent?.client_secret) {
    try {
      await store.createSetupIntent('card')
    }
    catch {
      cardError.value = t('companyBilling.setupIntentFailed')

      return
    }
  }

  isSaving.value = true
  cardError.value = ''

  try {
    const { error, setupIntent } = await stripe.confirmCardSetup(store.setupIntent.client_secret, {
      payment_method: { card: cardElement },
    })

    if (error) {
      cardError.value = error.message
      store._setupIntent = null

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
    store._setupIntent = null
  }
  finally {
    isSaving.value = false
  }
}

// ── SEPA step navigation ──

const goToSepaStep2 = () => {
  if (!sepaName.value.trim()) {
    cardError.value = t('companyBilling.sepaNameRequired')
    return
  }
  cardError.value = ''
  sepaStep.value = 2
}

// ── Save SEPA ──

const saveSepa = async () => {
  if (!stripe || !ibanElement)
    return

  // Recreate setup intent if missing or consumed
  if (!store.setupIntent?.client_secret) {
    try {
      await store.createSetupIntent('sepa_debit')
    }
    catch {
      cardError.value = t('companyBilling.setupIntentFailed')

      return
    }
  }

  isSaving.value = true
  cardError.value = ''

  try {
    const billingEmail = store.overview?.billing_email || authStore.user?.email || ''

    const { error, setupIntent } = await stripe.confirmSepaDebitSetup(store.setupIntent.client_secret, {
      payment_method: {
        sepa_debit: ibanElement,
        billing_details: {
          name: sepaName.value.trim(),
          email: billingEmail,
        },
      },
    })

    if (error) {
      cardError.value = error.message
      // Setup intent is consumed after error — clear so next attempt creates a new one
      store._setupIntent = null

      return
    }

    // Confirm synchronously — creates profile without waiting for webhook
    const result = await store.confirmSetupIntent(setupIntent.payment_method)

    // Set preferred debit day if chosen
    if (sepaPreferredDay.value && result.card?.id) {
      try { await store.setDebitDay(result.card.id, sepaPreferredDay.value) }
      catch { /* non-blocking */ }
    }

    closeForm()

    if (result.duplicate)
      toast(t('companyBilling.cardAlreadyExists'), 'info')
    else
      toast(t('companyBilling.ibanSaved'), 'success')
  }
  catch (e) {
    cardError.value = e?.data?.message || e?.message || t('companyBilling.setupIntentFailed')
    store._setupIntent = null
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
  mandateAccepted.value = false
  sepaPreferredDay.value = null
  sepaStep.value = 1

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
  actionLoading.value = `default-${card.id}`
  try {
    await store.setDefaultCard(card.id)
    toast(t('companyBilling.defaultCardUpdated'), 'success')
  }
  catch {
    toast(t('companyBilling.defaultCardFailed'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

// ── Delete ──

const confirmDelete = card => { deletingCard.value = card }
const cancelDelete = () => { deletingCard.value = null }

const executeDelete = async () => {
  const isSepa = deletingCard.value?.method_key === 'sepa_debit'

  actionLoading.value = `delete-${deletingCard.value.id}`
  try {
    await store.deleteSavedCard(deletingCard.value.id)
    toast(t(isSepa ? 'companyBilling.sepaDeleted' : 'companyBilling.cardDeleted'), 'success')
  }
  catch (err) {
    toast(err?.data?.message || t(isSepa ? 'companyBilling.sepaDeleteFailed' : 'companyBilling.cardDeleteFailed'), 'error')
  }
  finally {
    actionLoading.value = null
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
      <!-- Load error -->
      <VAlert
        v-if="loadError"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ t('common.loadError') }}
        <template #append>
          <VBtn
            variant="text"
            size="small"
            @click="fetchMethods"
          >
            {{ t('common.retry') }}
          </VBtn>
        </template>
      </VAlert>

      <!-- Empty state -->
      <VCard v-if="!loadError && store.savedCards.length === 0 && !showMethodPicker && !showCardForm && !showSepaForm">
        <EmptyState
          icon="tabler-credit-card"
          :title="t('companyBilling.noPaymentMethods')"
          :description="t('companyBilling.paymentSecurityNotice')"
        >
          <VBtn
            v-can="'billing.manage'"
            variant="tonal"
            color="primary"
            prepend-icon="tabler-plus"
            @click="showAddPicker"
          >
            {{ t('companyBilling.addPaymentMethod') }}
          </VBtn>
        </EmptyState>
      </VCard>

      <!-- ═══ Payment Methods Grid ═══ -->
      <VRow
        v-if="store.savedCards.length > 0"
        align="start"
      >
        <VCol
          v-for="pm in store.savedCards"
          :key="pm.id"
          cols="12"
          sm="6"
        >
          <VCard
            class="payment-card d-flex flex-column"
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
                    v-can="'billing.manage'"
                    size="small"
                    :title="t('companyBilling.setAsDefault')"
                    :loading="actionLoading === `default-${pm.id}`"
                    :disabled="!!actionLoading"
                    @click="setDefault(pm)"
                  >
                    <VIcon
                      icon="tabler-star"
                      size="18"
                    />
                  </IconBtn>
                  <VTooltip
                    v-if="store.savedCards.length <= 1"
                    location="top"
                  >
                    <template #activator="{ props: tp }">
                      <span v-bind="tp">
                        <IconBtn
                          size="small"
                          color="error"
                          disabled
                        >
                          <VIcon
                            icon="tabler-trash"
                            size="18"
                          />
                        </IconBtn>
                      </span>
                    </template>
                    {{ t('companyBilling.cannotDeleteLastCard') }}
                  </VTooltip>
                  <IconBtn
                    v-else
                    v-can="'billing.manage'"
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

            <VCardText class="pt-0 flex-grow-1">
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

                <!-- ADR-328 S3: Preferred debit day per IBAN -->
                <div class="d-flex align-center gap-2 mt-3">
                  <span class="text-body-2 text-disabled">{{ t('companyBilling.debitOn') }}</span>
                  <AppSelect
                    :model-value="pm.preferred_debit_day ? Number(pm.preferred_debit_day) : null"
                    :items="debitDayItems"
                    density="compact"
                    style="max-inline-size: 180px;"
                    :loading="debitDayLoading === pm.id"
                    :disabled="!!debitDayLoading"
                    hide-details
                    @update:model-value="val => updateDebitDay(pm.id, val)"
                  />
                </div>
              </template>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Add another card (dashed placeholder) — replaced by picker/form when clicked -->
        <VCol
          v-if="store.savedCards.length < store.maxPaymentMethods && !showMethodPicker && !showCardForm && !showSepaForm"
          cols="12"
          sm="6"
        >
          <VCard
            class="payment-card d-flex align-center justify-center cursor-pointer"
            variant="outlined"
            style="border-style: dashed;"
            @click="showAddPicker"
          >
            <VCardText class="text-center pa-4">
              <VIcon
                icon="tabler-plus"
                size="32"
                color="primary"
                class="mb-2"
              />
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('companyBilling.addAnotherMethod') }}
              </p>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Picker / Form (replaces dashed card in-place) -->
        <VCol
          v-if="showMethodPicker || showCardForm || showSepaForm"
          cols="12"
          sm="6"
        >
          <!-- Method type picker -->
          <VCard
            v-if="showMethodPicker"
            class="payment-card d-flex flex-column justify-center"
          >
            <VCardText class="pa-3">
              <p class="text-body-2 mb-3">
                {{ t('companyBilling.choosePaymentType') }}
              </p>
              <div class="d-flex gap-2">
                <VBtn
                  variant="outlined"
                  size="small"
                  :color="selectedMethod === 'card' ? 'primary' : 'secondary'"
                  prepend-icon="tabler-credit-card"
                  @click="openForm('card')"
                >
                  {{ t('companyBilling.addCardBtn') }}
                </VBtn>
                <VBtn
                  v-if="showSepaOption"
                  variant="outlined"
                  size="small"
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
            class="mb-4"
          >
            {{ cardError }}
          </VAlert>

          <!-- Card form -->
          <VCard
            v-if="showCardForm"
            class="payment-card d-flex flex-column"
          >
            <VCardText class="pa-3 flex-grow-1 d-flex flex-column">
              <div
                ref="cardElementRef"
                class="pa-3 rounded border"
                style="min-block-size: 38px;"
              />

              <div class="d-flex gap-2 mt-3">
                <VBtn
                  v-can="'billing.manage'"
                  color="primary"
                  size="small"
                  :loading="isSaving"
                  @click="saveCard"
                >
                  {{ t('companyBilling.saveCard') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  size="small"
                  @click="closeForm"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </div>

              <TrustBadges
                variant="horizontal"
                :show="['secure', 'gdpr']"
                class="mt-auto"
              />
            </VCardText>
          </VCard>

          <!-- SEPA form -->
          <VCard
            v-if="showSepaForm"
            class="payment-card d-flex flex-column"
          >
            <VCardText class="pa-3 flex-grow-1 d-flex flex-column">
              <!-- Step indicator -->
              <div class="d-flex align-center gap-2 mb-2">
                <VChip
                  :color="sepaStep === 1 ? 'primary' : 'success'"
                  size="x-small"
                  :variant="sepaStep === 1 ? 'elevated' : 'tonal'"
                >
                  1
                </VChip>
                <VDivider
                  class="flex-grow-0"
                  style="inline-size: 24px;"
                />
                <VChip
                  :color="sepaStep === 2 ? 'primary' : 'default'"
                  size="x-small"
                  :variant="sepaStep === 2 ? 'elevated' : 'tonal'"
                >
                  2
                </VChip>
              </div>

              <!-- Step 1: Titulaire + IBAN -->
              <div v-show="sepaStep === 1">
                <AppTextField
                  v-model="sepaName"
                  :label="t('companyBilling.accountHolder')"
                  density="compact"
                  class="mb-2"
                />
                <div
                  ref="ibanElementRef"
                  class="pa-3 rounded border mb-2"
                  style="min-block-size: 38px;"
                />
                <div class="d-flex gap-2 mt-2">
                  <VBtn
                    color="primary"
                    size="small"
                    @click="goToSepaStep2"
                  >
                    {{ t('common.next') }}
                  </VBtn>
                  <VBtn
                    variant="tonal"
                    size="small"
                    @click="closeForm"
                  >
                    {{ t('common.cancel') }}
                  </VBtn>
                </div>
              </div>

              <!-- Step 2: Mandat + date prélèvement + validation -->
              <div v-show="sepaStep === 2">
                <VCheckbox
                  v-model="mandateAccepted"
                  class="mb-1"
                  density="compact"
                >
                  <template #label>
                    <span class="text-caption">{{ t('companyBilling.sepaMandateCheckbox', { appName }) }}</span>
                  </template>
                </VCheckbox>

                <AppSelect
                  v-model="sepaPreferredDay"
                  :items="debitDayItems"
                  :label="t('companyBilling.preferredDebitDay')"
                  density="compact"
                  class="mb-2"
                />

                <div class="d-flex gap-2 mt-2">
                  <VBtn
                    variant="tonal"
                    size="small"
                    @click="sepaStep = 1"
                  >
                    {{ t('common.previous') }}
                  </VBtn>
                  <VBtn
                    v-can="'billing.manage'"
                    color="primary"
                    size="small"
                    :loading="isSaving"
                    :disabled="!mandateAccepted"
                    prepend-icon="tabler-building-bank"
                    @click="saveSepa"
                  >
                    {{ t('companyBilling.saveIban') }}
                  </VBtn>
                  <VBtn
                    variant="tonal"
                    size="small"
                    @click="closeForm"
                  >
                    {{ t('common.cancel') }}
                  </VBtn>
                </div>
              </div>
            </VCardText>
          </VCard>
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
    </template>
  </div>

  <!-- Delete confirmation dialog -->
  <VDialog
    :model-value="!!deletingCard"
    max-width="400"
    @update:model-value="!$event && cancelDelete()"
  >
    <VCard>
      <VCardTitle>{{ deletingCard?.method_key === 'sepa_debit' ? t('companyBilling.confirmDeleteSepa') : t('companyBilling.confirmDeleteCard') }}</VCardTitle>
      <VCardText>{{ deletingCard?.method_key === 'sepa_debit' ? t('companyBilling.confirmDeleteSepaDesc') : t('companyBilling.confirmDeleteCardDesc') }}</VCardText>
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
          :loading="actionLoading?.startsWith('delete-')"
          @click="executeDelete"
        >
          {{ t('common.delete') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.payment-card {
  aspect-ratio: 1.9;
}
</style>
