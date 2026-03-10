<script setup>
import { formatMoney } from '@/utils/money'
import TrustBadges from '@/core/components/TrustBadges.vue'
import { $api } from '@/utils/api'

const { t } = useI18n()

const props = defineProps({
  // Summary data
  selectedJobdomainLabel: { type: String, default: '—' },
  selectedPlanData: { type: Object, default: null },
  annualToggle: { type: Boolean, default: false },
  displayPrice: { type: String, default: '' },
  hasTrial: { type: Boolean, default: false },
  selectedAddons: { type: Array, default: () => [] },
  selectedAddonLabels: { type: Array, default: () => [] },
  addonsTotalPrice: { type: Number, default: 0 },
  form: { type: Object, required: true },
  legalStatusKey: { type: String, default: null },
  selectedLegalStatusLabel: { type: String, default: '—' },
  selectedMarketLabel: { type: String, default: '—' },
  selectedMarketKey: { type: String, default: null },
  filledDynamicFields: { type: Array, default: () => [] },
  // Pricing
  hasCost: { type: Boolean, default: false },
  planPrice: { type: Number, default: 0 },
  totalMonthlyPrice: { type: Number, default: 0 },
  immediatePaymentAmount: { type: Number, default: 0 },
  trialChargeImmediate: { type: Boolean, default: false },
  needsPayment: { type: Boolean, default: false },
  // State
  errorMessage: { type: String, default: '' },
  paymentPhase: { type: Boolean, default: false },
  cardError: { type: String, default: '' },
  paymentSuccess: { type: Boolean, default: false },
  isConfirmingPayment: { type: Boolean, default: false },
  isLoading: { type: Boolean, default: false },
  // Payment method
  selectedPaymentMethod: { type: String, default: 'card' },
  sepaName: { type: String, default: '' },
  sepaEmail: { type: String, default: '' },
})

const emit = defineEmits([
  'goToStep',
  'submit',
  'confirmPayment',
  'clearError',
  'clearCardError',
  'retryPayment',
  'switchPaymentMethod',
  'update:sepaName',
  'update:sepaEmail',
])

// ─── Coupon code ─────────────────────────────────────────
const couponCode = ref('')
const couponLoading = ref(false)
const couponResult = ref(null)
const couponError = ref('')

const applyCoupon = async () => {
  if (!couponCode.value.trim()) return

  couponLoading.value = true
  couponError.value = ''
  couponResult.value = null

  try {
    const data = await $api('/public/validate-coupon', {
      method: 'POST',
      body: {
        code: couponCode.value.trim(),
        plan_key: props.selectedPlanData?.key || '',
        subtotal_cents: props.totalMonthlyPrice,
      },
    })

    if (data.valid) {
      couponResult.value = data
    }
    else {
      couponError.value = t(`register.couponError_${data.error}`) || t('register.couponInvalid')
    }
  }
  catch {
    couponError.value = t('register.couponInvalid')
  }
  finally {
    couponLoading.value = false
  }
}

const clearCoupon = () => {
  couponCode.value = ''
  couponResult.value = null
  couponError.value = ''
}

// ─── Server-side pricing estimate (ADR-324) ─────────────
const pricingEstimate = ref(null)
const pricingLoading = ref(false)

const fetchEstimate = async () => {
  const planKey = props.selectedPlanData?.key
  const marketKey = props.selectedMarketKey
  if (!planKey || !marketKey) {
    pricingEstimate.value = null
    return
  }

  pricingLoading.value = true
  try {
    const data = await $api('/public/plans/estimate-registration', {
      method: 'POST',
      body: {
        plan_key: planKey,
        interval: props.annualToggle ? 'yearly' : 'monthly',
        market_key: marketKey,
        coupon_code: couponResult.value ? couponCode.value : undefined,
        addon_keys: props.selectedAddons.length > 0 ? props.selectedAddons : undefined,
      },
    })
    pricingEstimate.value = data
  }
  catch {
    pricingEstimate.value = null
  }
  finally {
    pricingLoading.value = false
  }
}

watch(
  () => [props.selectedPlanData?.key, props.annualToggle, props.selectedMarketKey, props.selectedAddons, couponResult.value],
  fetchEstimate,
  { immediate: true, deep: true },
)

// Re-fetch when coupon applied/cleared
watch(couponResult, fetchEstimate)

// ADR-325: Allowed payment methods from backend (replaces hardcoded hasTrial check)
const allowedPaymentMethods = computed(() => pricingEstimate.value?.allowed_payment_methods ?? ['card'])
const showSepaOption = computed(() => allowedPaymentMethods.value.includes('sepa_debit'))

// Stripe element mount points — exposed to parent
const cardElementRef = ref(null)
const ibanElementRef = ref(null)

defineExpose({
  cardElementRef,
  ibanElementRef,
  couponCode: computed(() => couponResult.value ? couponCode.value : null),
})
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('register.reviewAndSubmit') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('register.reviewDescription') }}
  </p>

  <VAlert
    v-if="errorMessage"
    type="error"
    class="mb-6"
    closable
    @click:close="emit('clearError')"
  >
    {{ errorMessage }}
  </VAlert>

  <!-- Industry -->
  <VCard
    flat
    border
    class="mb-4"
  >
    <VCardText class="d-flex justify-space-between align-center">
      <div>
        <h6 class="text-h6 mb-1">
          {{ t('register.sectionIndustry') }}
        </h6>
        <p class="text-body-2 mb-0">
          {{ selectedJobdomainLabel }}
        </p>
      </div>
      <VBtn
        variant="text"
        size="small"
        @click="emit('goToStep', 0)"
      >
        <VIcon
          icon="tabler-pencil"
          start
        />
        {{ t('register.edit') }}
      </VBtn>
    </VCardText>
  </VCard>

  <!-- Plan -->
  <VCard
    flat
    border
    class="mb-4"
  >
    <VCardText class="d-flex justify-space-between align-center">
      <div>
        <h6 class="text-h6 mb-1">
          {{ t('register.sectionPlan') }}
        </h6>
        <p class="text-body-2 mb-0">
          {{ selectedPlanData?.name || '—' }}
          · {{ annualToggle ? t('common.annually') : t('common.monthly') }}
          · {{ displayPrice }}{{ t('common.perMonth') }}
        </p>
        <VChip
          v-if="hasTrial"
          color="info"
          variant="tonal"
          size="small"
          prepend-icon="tabler-clock"
          class="mt-1"
        >
          {{ t('register.trialInfo', { days: selectedPlanData?.trial_days }) }}
        </VChip>
      </div>
      <VBtn
        variant="text"
        size="small"
        @click="emit('goToStep', 1)"
      >
        <VIcon
          icon="tabler-pencil"
          start
        />
        {{ t('register.edit') }}
      </VBtn>
    </VCardText>
  </VCard>

  <!-- Addons -->
  <VCard
    v-if="selectedAddons.length > 0"
    flat
    border
    class="mb-4"
  >
    <VCardText class="d-flex justify-space-between align-center">
      <div>
        <h6 class="text-h6 mb-1">
          {{ t('register.sectionAddons') }}
        </h6>
        <p class="text-body-2 mb-0">
          {{ selectedAddonLabels.join(', ') }}
        </p>
        <p class="text-body-2 text-primary mb-0">
          +{{ formatMoney(addonsTotalPrice) }}{{ t('common.perMonth') }}
        </p>
      </div>
      <VBtn
        variant="text"
        size="small"
        @click="emit('goToStep', 2)"
      >
        <VIcon
          icon="tabler-pencil"
          start
        />
        {{ t('register.edit') }}
      </VBtn>
    </VCardText>
  </VCard>

  <!-- Company -->
  <VCard
    flat
    border
    class="mb-4"
  >
    <VCardText class="d-flex justify-space-between align-center">
      <div>
        <h6 class="text-h6 mb-1">
          {{ t('register.sectionCompany') }}
        </h6>
        <p class="text-body-2 mb-0">
          {{ form.company_name }}
          <span v-if="legalStatusKey"> — {{ selectedLegalStatusLabel }}</span>
          · {{ selectedMarketLabel }}
        </p>
        <p
          v-if="filledDynamicFields.length > 0"
          class="text-body-2 text-medium-emphasis mb-0 mt-1"
        >
          {{ filledDynamicFields.map(f => `${f.label}: ${f.value}`).join(' · ') }}
        </p>
      </div>
      <VBtn
        variant="text"
        size="small"
        @click="emit('goToStep', 3)"
      >
        <VIcon
          icon="tabler-pencil"
          start
        />
        {{ t('register.edit') }}
      </VBtn>
    </VCardText>
  </VCard>

  <!-- Account -->
  <VCard
    flat
    border
    class="mb-4"
  >
    <VCardText class="d-flex justify-space-between align-center">
      <div>
        <h6 class="text-h6 mb-1">
          {{ t('register.sectionAccount') }}
        </h6>
        <p class="text-body-2 mb-0">
          {{ form.first_name }} {{ form.last_name }} — {{ form.email }}
        </p>
      </div>
      <VBtn
        variant="text"
        size="small"
        @click="emit('goToStep', 4)"
      >
        <VIcon
          icon="tabler-pencil"
          start
        />
        {{ t('register.edit') }}
      </VBtn>
    </VCardText>
  </VCard>

  <!-- Pricing breakdown (ADR-324: server-side PricingEngine) -->
  <VCard
    v-if="hasCost"
    variant="outlined"
    class="mb-6"
  >
    <VCardText>
      <!-- Plan line -->
      <div class="d-flex justify-space-between align-center">
        <span class="text-body-1">
          {{ selectedPlanData?.name }}
        </span>
        <span class="text-body-1 font-weight-medium">
          {{ formatMoney(planPrice) }}{{ t('common.perMonth') }}
        </span>
      </div>

      <!-- Addon lines -->
      <template v-if="addonsTotalPrice > 0">
        <VDivider class="my-2" />
        <div class="d-flex justify-space-between align-center">
          <span class="text-body-1">
            {{ t('register.sectionAddons') }} ({{ selectedAddons.length }})
          </span>
          <span class="text-body-1 font-weight-medium">
            {{ formatMoney(addonsTotalPrice) }}{{ t('common.perMonth') }}
          </span>
        </div>
      </template>

      <!-- Coupon code -->
      <VDivider class="my-2" />
      <div v-if="!couponResult" class="d-flex gap-2 align-center">
        <AppTextField
          v-model="couponCode"
          :label="t('register.couponCode')"
          :error-messages="couponError ? [couponError] : []"
          density="compact"
          class="flex-grow-1"
          @keyup.enter="applyCoupon"
        />
        <VBtn
          variant="tonal"
          color="primary"
          :loading="couponLoading"
          :disabled="!couponCode.trim()"
          @click="applyCoupon"
        >
          {{ t('register.applyCoupon') }}
        </VBtn>
      </div>
      <div
        v-else
        class="d-flex justify-space-between align-center"
      >
        <div class="d-flex align-center gap-2">
          <VChip
            color="success"
            size="small"
          >
            <VIcon
              icon="tabler-ticket"
              start
              size="14"
            />
            {{ couponResult.coupon_name }}
          </VChip>
          <VBtn
            icon
            variant="text"
            size="x-small"
            @click="clearCoupon"
          >
            <VIcon
              icon="tabler-x"
              size="14"
            />
          </VBtn>
        </div>
        <span class="text-body-1 font-weight-medium text-success">
          -{{ formatMoney(pricingEstimate?.coupon?.discount ?? couponResult.discount_preview) }}
        </span>
      </div>

      <!-- Subtotal HT -->
      <VDivider class="my-2" />
      <div class="d-flex justify-space-between align-center">
        <span class="text-body-1">
          {{ t('register.subtotalHT') }}
        </span>
        <span class="text-body-1 font-weight-medium">
          {{ formatMoney(pricingEstimate?.subtotal ?? totalMonthlyPrice) }}{{ t('common.perMonth') }}
        </span>
      </div>

      <!-- Tax (TVA) — from server estimate -->
      <template v-if="pricingEstimate && pricingEstimate.tax_amount > 0">
        <div class="d-flex justify-space-between align-center mt-1">
          <span class="text-body-2 text-disabled">
            {{ t('register.taxLabel') }} ({{ (pricingEstimate.tax_rate_bps / 100).toFixed(1) }}%)
          </span>
          <span class="text-body-2">
            +{{ formatMoney(pricingEstimate.tax_amount) }}
          </span>
        </div>
      </template>

      <!-- Tax exemption -->
      <template v-if="pricingEstimate?.tax_exemption_reason">
        <div class="mt-1">
          <VChip
            color="info"
            variant="tonal"
            size="small"
          >
            {{ t('billing.tax_exemption.' + pricingEstimate.tax_exemption_reason) }}
          </VChip>
        </div>
      </template>

      <!-- Total TTC -->
      <VDivider class="my-2" />
      <div class="d-flex justify-space-between align-center">
        <h6 class="text-h6">
          {{ t('register.totalTTC') }}
        </h6>
        <h5 class="text-h5 font-weight-bold">
          {{ formatMoney(pricingEstimate?.total ?? totalMonthlyPrice) }}{{ t('common.perMonth') }}
        </h5>
      </div>

      <!-- Loading indicator -->
      <div v-if="pricingLoading" class="text-center mt-2">
        <VProgressLinear indeterminate color="primary" height="2" />
      </div>

      <!-- Trial: "0€ today" prominent message -->
      <VAlert
        v-if="hasTrial && !trialChargeImmediate"
        type="success"
        variant="tonal"
        class="mt-3"
      >
        <div class="d-flex justify-space-between align-center">
          <div>
            <span class="text-body-1 font-weight-bold">{{ t('register.dueToday') }}</span>
          </div>
          <span class="text-h5 font-weight-bold text-success">{{ formatMoney(0) }}</span>
        </div>
        <p class="text-body-2 mb-0 mt-1">
          {{ t('register.firstPaymentAfterTrial', { days: selectedPlanData?.trial_days }) }}
        </p>
      </VAlert>

      <!-- Due now vs deferred (trial with addons that charge immediately) -->
      <template v-if="hasTrial && !trialChargeImmediate && addonsTotalPrice > 0">
        <VDivider class="my-2" />
        <div class="d-flex justify-space-between align-center text-warning">
          <span class="text-body-2">{{ t('register.addonsDueNow') }}</span>
          <span class="text-body-1 font-weight-bold">{{ formatMoney(addonsTotalPrice) }}</span>
        </div>
      </template>
    </VCardText>
  </VCard>

  <!-- Payment phase: Stripe Card/SEPA Element (ADR-302) -->
  <template v-if="paymentPhase">
    <VDivider class="my-4" />
    <h5 class="text-h5 mb-2">
      <VIcon
        icon="tabler-credit-card"
        class="me-1"
      />
      {{ t('register.paymentTitle') }}
    </h5>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ hasTrial ? t('register.paymentDescriptionTrial') : t('register.paymentDescription') }}
    </p>

    <VAlert
      v-if="cardError"
      type="error"
      class="mb-4"
      closable
      @click:close="emit('clearCardError')"
    >
      {{ cardError }}
      <template #append>
        <VBtn
          variant="text"
          size="small"
          @click="emit('retryPayment')"
        >
          {{ t('register.retryPayment') }}
        </VBtn>
      </template>
    </VAlert>

    <VAlert
      v-if="paymentSuccess"
      type="success"
      class="mb-4"
    >
      {{ t('register.paymentSuccess') }}
    </VAlert>

    <!-- Payment method selector — ADR-325: driven by backend allowed_payment_methods -->
    <VRadioGroup
      v-if="showSepaOption"
      :model-value="selectedPaymentMethod"
      inline
      class="mb-4"
    >
      <VRadio
        value="card"
        @click="emit('switchPaymentMethod', 'card')"
      >
        <template #label>
          <VIcon
            icon="tabler-credit-card"
            class="me-1"
          />
          {{ t('register.paymentCard') }}
        </template>
      </VRadio>
      <VRadio
        value="sepa_debit"
        @click="emit('switchPaymentMethod', 'sepa_debit')"
      >
        <template #label>
          <VIcon
            icon="tabler-building-bank"
            class="me-1"
          />
          {{ t('register.paymentSepa') }}
        </template>
      </VRadio>
    </VRadioGroup>

    <!-- Card element -->
    <div
      v-show="selectedPaymentMethod === 'card'"
      ref="cardElementRef"
      class="stripe-card-element pa-4 rounded border mb-4"
    />

    <!-- SEPA element -->
    <template v-if="selectedPaymentMethod === 'sepa_debit'">
      <AppTextField
        :model-value="sepaName"
        :label="t('register.sepaHolderName')"
        class="mb-4"
        @update:model-value="emit('update:sepaName', $event)"
      />
      <AppTextField
        :model-value="sepaEmail"
        :label="t('register.sepaEmail')"
        type="email"
        class="mb-4"
        @update:model-value="emit('update:sepaEmail', $event)"
      />
      <div
        ref="ibanElementRef"
        class="stripe-card-element pa-4 rounded border mb-4"
      />
      <p class="text-caption text-medium-emphasis mb-4">
        {{ t('register.sepaMandate') }}
      </p>
    </template>

    <VBtn
      block
      size="x-large"
      color="primary"
      :loading="isConfirmingPayment"
      :disabled="paymentSuccess"
      @click="emit('confirmPayment')"
    >
      <VIcon
        icon="tabler-lock"
        start
      />
      {{ immediatePaymentAmount > 0
        ? t('register.confirmPayment', { amount: formatMoney(immediatePaymentAmount) })
        : t('register.savePaymentMethod')
      }}
    </VBtn>

    <TrustBadges
      variant="horizontal"
      :show="['secure', 'gdpr', 'cancel']"
      class="justify-center mt-3"
    />
  </template>

  <!-- Pre-payment: Submit button -->
  <template v-else>
    <VBtn
      block
      size="x-large"
      :loading="isLoading"
      @click="emit('submit')"
    >
      <VIcon
        icon="tabler-lock"
        start
      />
      {{ hasTrial
        ? t('register.startFreeTrial')
        : needsPayment
          ? t('register.proceedToPayment')
          : t('register.createAccountBtn')
      }}
    </VBtn>

    <TrustBadges
      v-if="needsPayment"
      variant="horizontal"
      :show="hasTrial ? ['secure', 'gdpr', 'cancel', 'trial'] : ['secure', 'gdpr', 'cancel']"
      class="justify-center mt-3"
    />
  </template>

  <div class="text-center mt-4">
    <span class="d-inline-block">{{ t('auth.alreadyHaveAccount') }}</span>
    <RouterLink
      class="text-primary ms-1 d-inline-block"
      to="/login"
    >
      {{ t('auth.signInInstead') }}
    </RouterLink>
  </div>
</template>
