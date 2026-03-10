<script setup>
const { t, locale } = useI18n()
const { toast } = useAppToast()

import { loadStripe } from '@stripe/stripe-js'
import { formatMoney } from '@/utils/money'
import { useAuthStore } from '@/core/stores/auth'
import { useRuntimeStore } from '@/core/runtime/runtime'
import { usePublicPlans } from '@/composables/usePublicPlans'
import { usePublicFields } from '@/composables/usePublicFields'
import { usePublicMarkets } from '@/composables/usePublicMarkets'
import { usePublicAddons } from '@/composables/usePublicAddons'
import { useAnalytics } from '@/composables/useAnalytics'
import { useTunnelPersistence } from '@/composables/useTunnelPersistence'
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import authV2RegisterIllustrationBorderedDark from '@images/pages/auth-v2-register-illustration-bordered-dark.png'
import authV2RegisterIllustrationBorderedLight from '@images/pages/auth-v2-register-illustration-bordered-light.png'
import authV2RegisterIllustrationDark from '@images/pages/auth-v2-register-illustration-dark.png'
import authV2RegisterIllustrationLight from '@images/pages/auth-v2-register-illustration-light.png'
import authV2MaskDark from '@images/pages/misc-mask-dark.png'
import authV2MaskLight from '@images/pages/misc-mask-light.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'
import RegisterStepIndustry from './_RegisterStepIndustry.vue'
import RegisterStepPlan from './_RegisterStepPlan.vue'
import RegisterStepAddons from './_RegisterStepAddons.vue'
import RegisterStepCompany from './_RegisterStepCompany.vue'
import RegisterStepAccount from './_RegisterStepAccount.vue'
import RegisterStepSummary from './_RegisterStepSummary.vue'

definePage({
  meta: {
    layout: 'blank',
    public: true,
    unauthenticatedOnly: true,
  },
})

usePublicTheme()

const auth = useAuthStore()
const runtime = useRuntimeStore()
const router = useRouter()
const { track } = useAnalytics()
const persistence = useTunnelPersistence()

// ─── Wizard state ──────────────────────────────────────────
const currentStep = ref(0)

const stepItems = computed(() => [
  { title: t('register.industryStep'), subtitle: t('register.industryStepSubtitle'), icon: 'tabler-briefcase' },
  { title: t('register.planStep'), subtitle: t('register.planStepSubtitle'), icon: 'tabler-credit-card' },
  { title: t('register.addonsStep'), subtitle: t('register.addonsStepSubtitle'), icon: 'tabler-puzzle' },
  { title: t('register.companyStep'), subtitle: t('register.companyStepSubtitle'), icon: 'tabler-building' },
  { title: t('register.accountStep'), subtitle: t('register.accountStepSubtitle'), icon: 'tabler-user' },
  { title: t('register.summaryStep'), subtitle: t('register.summaryStepSubtitle'), icon: 'tabler-check' },
])

// ─── Public data ──────────────────────────────────────────
const { plans, jobdomains, billingPolicy, loading: plansLoading, previewModules, fetchPlans, fetchPreview } = usePublicPlans()
const { fields: companyFields, loading: fieldsLoading, fetchFields } = usePublicFields()
const { markets, legalStatuses, loading: marketsLoading, legalStatusesLoading, fetchMarkets, fetchLegalStatuses } = usePublicMarkets()
const { addons, currency: addonCurrency, loading: addonsLoading, fetchAddons } = usePublicAddons()

// ─── Locale detection ────────────────────────────────────
function detectLocaleFromBrowser() {
  const lang = navigator.language || navigator.userLanguage || 'fr'

  return lang.startsWith('fr') ? 'fr' : 'en'
}

function detectMarketFromBrowser(availableMarkets) {
  const lang = navigator.language || navigator.userLanguage || 'fr-FR'
  const parts = lang.split('-')
  const countryCode = (parts[1] || parts[0]).toUpperCase()

  const byCountry = availableMarkets.find(m => m.key === countryCode)
  if (byCountry) return byCountry.key

  const langLower = parts[0].toLowerCase()
  const langToCountry = { fr: 'FR', en: 'GB', de: 'DE', es: 'ES', it: 'IT', nl: 'NL', pt: 'PT' }

  if (langToCountry[langLower]) {
    const byLang = availableMarkets.find(m => m.key === langToCountry[langLower])
    if (byLang) return byLang.key
  }

  return availableMarkets[0]?.key || null
}

onMounted(() => {
  const browserLocale = detectLocaleFromBrowser()

  locale.value = browserLocale
  useCookie('language').value = browserLocale

  fetchPlans().catch(() => toast(t('register.loadError'), 'error'))
  fetchMarkets().catch(() => toast(t('register.loadError'), 'error'))

  track('registration_started', { source: document.referrer || 'direct' })

  const saved = persistence.load()
  if (saved) {
    if (saved.currentStep != null) currentStep.value = saved.currentStep
    if (saved.selectedJobdomain) selectedJobdomain.value = saved.selectedJobdomain
    if (saved.selectedPlan) selectedPlan.value = saved.selectedPlan
    if (saved.annualToggle != null) annualToggle.value = saved.annualToggle
    if (saved.selectedAddons) selectedAddons.value = saved.selectedAddons
    if (saved.selectedMarket) selectedMarket.value = saved.selectedMarket
    if (saved.legalStatusKey) legalStatusKey.value = saved.legalStatusKey
    if (saved.dynamicFieldValues) dynamicFieldValues.value = saved.dynamicFieldValues
    if (saved.billingIsSameAsCompany != null) billingIsSameAsCompany.value = saved.billingIsSameAsCompany
    if (saved.first_name) form.value.first_name = saved.first_name
    if (saved.last_name) form.value.last_name = saved.last_name
    if (saved.email) form.value.email = saved.email
    if (saved.company_name) form.value.company_name = saved.company_name
  }
})

// ─── Form state ───────────────────────────────────────────
const selectedJobdomain = ref(null)
const selectedMarket = ref(null)
const selectedPlan = ref('starter')
const annualToggle = ref(false)
const legalStatusKey = ref(null)
const dynamicFieldValues = ref({})
const selectedAddons = ref([])
const billingIsSameAsCompany = ref(true)

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
  password: '',
  company_name: '',
})

const isLoading = ref(false)
const errorMessage = ref('')
const errors = ref({})

// ─── Stripe payment state (ADR-302) ─────────────────────
const paymentPhase = ref(false)
const cardError = ref('')
const isConfirmingPayment = ref(false)
const paymentSuccess = ref(false)
let stripe = null
let cardElement = null
const pendingCheckout = ref(null)

const needsPayment = computed(() => hasCost.value)

// ─── Persistence auto-save & step tracking ──────────────
const stepNames = ['industry', 'plan', 'addons', 'company', 'account', 'summary']
let stepEnteredAt = Date.now()

watch(currentStep, (newStep, oldStep) => {
  const durationMs = Date.now() - stepEnteredAt

  if (oldStep != null)
    track('registration_step_completed', { step: oldStep, step_name: stepNames[oldStep], duration_ms: durationMs })
  track('registration_step_viewed', { step: newStep, step_name: stepNames[newStep] })
  stepEnteredAt = Date.now()
})

const tunnelState = computed(() => ({
  currentStep: currentStep.value,
  selectedJobdomain: selectedJobdomain.value,
  selectedPlan: selectedPlan.value,
  annualToggle: annualToggle.value,
  selectedAddons: selectedAddons.value,
  selectedMarket: selectedMarket.value,
  legalStatusKey: legalStatusKey.value,
  dynamicFieldValues: dynamicFieldValues.value,
  billingIsSameAsCompany: billingIsSameAsCompany.value,
  first_name: form.value.first_name,
  last_name: form.value.last_name,
  email: form.value.email,
  company_name: form.value.company_name,
}))

persistence.autoSave(tunnelState)

if (typeof window !== 'undefined') {
  window.addEventListener('beforeunload', () => {
    if (!paymentSuccess.value)
      track('registration_abandoned', { step: currentStep.value, step_name: stepNames[currentStep.value] })
  })
}

// ─── Reactive data fetching ──────────────────────────────
const languageCookie = useCookie('language')

watch([selectedJobdomain, selectedMarket], async ([jd, mk]) => {
  if (jd) {
    const market = markets.value.find(m => m.key === mk)
    const marketLocale = market?.locale?.substring(0, 2) || 'fr'

    locale.value = marketLocale
    languageCookie.value = marketLocale

    await nextTick()
    await fetchFields(jd, mk)

    const newCodes = new Set(companyFields.value.map(f => f.code))
    const pruned = {}

    for (const [k, v] of Object.entries(dynamicFieldValues.value)) {
      if (newCodes.has(k))
        pruned[k] = v
    }
    dynamicFieldValues.value = pruned
  }
  if (mk) {
    await fetchLegalStatuses(mk)
    if (!legalStatuses.value.find(ls => ls.key === legalStatusKey.value))
      legalStatusKey.value = null
  }
})

watch([selectedJobdomain, selectedPlan], ([jd, plan]) => {
  if (jd && plan)
    fetchPreview(jd, plan)
})

watch(selectedPlan, planKey => {
  if (!planKey) return
  const plan = plans.value.find(p => p.key === planKey)

  track('plan_selected', {
    plan_key: planKey,
    interval: annualToggle.value ? 'yearly' : 'monthly',
    price: plan ? (annualToggle.value ? plan.price_yearly : plan.price_monthly) : 0,
    trial_days: plan?.trial_days || 0,
  })
})

watch([selectedJobdomain, selectedPlan, selectedMarket], ([jd, plan, mk]) => {
  if (jd && plan)
    fetchAddons(jd, plan, mk)
  selectedAddons.value = []
})

watch(markets, mks => {
  if (!mks.length) return
  if (!selectedMarket.value)
    selectedMarket.value = detectMarketFromBrowser(mks)
})

const emailManuallyEdited = ref(false)

watch(() => dynamicFieldValues.value.billing_email, email => {
  if (email && !emailManuallyEdited.value)
    form.value.email = email
})

// ─── Computed helpers ────────────────────────────────────
const addonsTotalPrice = computed(() => {
  return addons.value
    .filter(a => selectedAddons.value.includes(a.key))
    .reduce((sum, a) => sum + a.price, 0)
})

const selectedAddonLabels = computed(() => {
  return addons.value
    .filter(a => selectedAddons.value.includes(a.key))
    .map(a => a.name)
})

const selectedJobdomainLabel = computed(() => {
  const jd = jobdomains.value.find(j => j.key === selectedJobdomain.value)

  return jd?.label || selectedJobdomain.value || '—'
})

const selectedMarketLabel = computed(() => {
  const mk = markets.value.find(m => m.key === selectedMarket.value)

  return mk?.name || selectedMarket.value || '—'
})

const selectedMarketData = computed(() =>
  markets.value.find(m => m.key === selectedMarket.value),
)

const marketDialCode = computed(() => selectedMarketData.value?.dial_code || '+33')

const selectedPlanData = computed(() => plans.value.find(p => p.key === selectedPlan.value))

const selectedLegalStatusLabel = computed(() => {
  const ls = legalStatuses.value.find(l => l.key === legalStatusKey.value)

  return ls ? `${ls.name}` : '—'
})

const planPrice = computed(() => {
  const plan = selectedPlanData.value

  if (!plan)
    return 0

  return annualToggle.value ? Math.round(plan.price_yearly / 12 * 100) : plan.price_monthly * 100
})

const displayPrice = computed(() => formatMoney(planPrice.value))

const totalMonthlyPrice = computed(() => planPrice.value + addonsTotalPrice.value)

const hasCost = computed(() => totalMonthlyPrice.value > 0)

const hasTrial = computed(() => {
  const plan = selectedPlanData.value

  return plan?.trial_days > 0
})

const trialChargeImmediate = computed(() =>
  billingPolicy.value?.trial_charge_timing === 'immediate'
  || pendingCheckout.value?.trial_charge_timing === 'immediate',
)

const immediatePaymentAmount = computed(() => {
  if (!hasTrial.value || trialChargeImmediate.value)
    return totalMonthlyPrice.value
  return addonsTotalPrice.value
})

const needsImmediatePayment = computed(() => immediatePaymentAmount.value > 0)

const filledDynamicFields = computed(() => {
  return companyFields.value
    .filter(f => dynamicFieldValues.value[f.code])
    .map(f => ({ label: f.label, value: dynamicFieldValues.value[f.code] }))
})

// ─── Step validation ─────────────────────────────────────
const canAdvance = computed(() => {
  switch (currentStep.value) {
    case 0: return !!selectedJobdomain.value
    case 1: return !!selectedPlan.value
    case 2: return true
    case 3: return !!form.value.company_name && !!selectedMarket.value
    case 4: return !!(form.value.first_name && form.value.last_name && form.value.email && form.value.password)
    default: return true
  }
})

// ─── Submit ──────────────────────────────────────────────
const handleRegister = async () => {
  isLoading.value = true
  errorMessage.value = ''
  errors.value = {}

  try {
    const data = await auth.register({
      first_name: form.value.first_name,
      last_name: form.value.last_name,
      email: form.value.email,
      password: form.value.password,
      company_name: form.value.company_name,
      jobdomain_key: selectedJobdomain.value || undefined,
      plan_key: selectedPlan.value || undefined,
      billing_interval: annualToggle.value ? 'yearly' : 'monthly',
      market_key: selectedMarket.value || undefined,
      legal_status_key: legalStatusKey.value || undefined,
      dynamic_fields: dynamicFieldValues.value,
      addon_keys: selectedAddons.value.length > 0 ? selectedAddons.value : undefined,
      billing_same_as_company: billingIsSameAsCompany.value,
      coupon_code: summaryRef.value?.couponCode || undefined,
    })

    if (data.checkout?.mode === 'embedded' && data.checkout?.client_secret) {
      pendingCheckout.value = {
        client_secret: data.checkout.client_secret,
        publishable_key: data.checkout.publishable_key,
        subscription_id: data.checkout.subscription_id,
        trial_charge_timing: data.checkout.trial_charge_timing || null,
      }
      paymentPhase.value = true
      isLoading.value = false
      await mountStripeCard()

      return
    }

    track('registration_completed', {
      plan: selectedPlan.value,
      interval: annualToggle.value ? 'yearly' : 'monthly',
      has_trial: hasTrial.value,
      addons_count: selectedAddons.value.length,
    })
    persistence.clear()
    runtime.teardown()
    await runtime.boot('company')
    toast(t('register.registrationSuccess'), 'success')
    await router.replace('/dashboard')
  }
  catch (error) {
    if (error?.data?.errors) {
      errors.value = error.data.errors

      // Navigate to the account step if field errors exist there
      if (errors.value.email || errors.value.password || errors.value.first_name || errors.value.last_name)
        currentStep.value = 4

      // Show a friendly summary instead of Laravel's generic "(and N more errors)"
      errorMessage.value = t('register.pleaseCorrectErrors')
    }
    else {
      errorMessage.value = error?.data?.message || t('auth.registrationFailed')
    }
  }
  finally {
    isLoading.value = false
  }
}

// ─── Stripe Payment (ADR-302) ───────────────────────────
const selectedPaymentMethod = ref('card')
let ibanElement = null
const sepaName = ref('')
const sepaEmail = ref('')
const summaryRef = ref(null)

const stripeElementStyle = computed(() => {
  const isDark = document.documentElement.classList.contains('dark') || window.matchMedia('(prefers-color-scheme: dark)').matches

  return {
    base: {
      fontSize: '16px',
      fontFamily: '"Public Sans", sans-serif',
      color: isDark ? '#e7e3fc' : '#424242',
      '::placeholder': { color: isDark ? '#6e6b7b' : '#aab7c4' },
      iconColor: isDark ? '#e7e3fc' : '#424242',
    },
    invalid: { color: '#ff4c51' },
  }
})

const mountStripeCard = async () => {
  if (!pendingCheckout.value?.publishable_key) return

  stripe = await loadStripe(pendingCheckout.value.publishable_key)

  const elements = stripe.elements()

  await nextTick()

  cardElement = elements.create('card', {
    hidePostalCode: true,
    style: stripeElementStyle.value,
  })

  cardElement.mount(summaryRef.value.cardElementRef)
}

const switchToSepa = async () => {
  track('payment_method_selected', { method: 'sepa_debit' })
  selectedPaymentMethod.value = 'sepa_debit'
  if (cardElement) {
    cardElement.unmount()
    cardElement = null
  }
  await nextTick()
  if (!stripe) return

  const elements = stripe.elements()

  ibanElement = elements.create('iban', {
    supportedCountries: ['SEPA'],
    style: stripeElementStyle.value,
  })
  ibanElement.mount(summaryRef.value.ibanElementRef)
}

const switchToCard = async () => {
  track('payment_method_selected', { method: 'card' })
  selectedPaymentMethod.value = 'card'
  if (ibanElement) {
    ibanElement.unmount()
    ibanElement = null
  }
  await nextTick()
  if (!stripe) return

  const elements = stripe.elements()

  cardElement = elements.create('card', {
    hidePostalCode: true,
    style: stripeElementStyle.value,
  })
  cardElement.mount(summaryRef.value.cardElementRef)
}

const handleSwitchPaymentMethod = method => {
  if (method === 'sepa_debit')
    switchToSepa()
  else
    switchToCard()
}

const confirmPayment = async () => {
  if (!stripe || !pendingCheckout.value?.client_secret) return

  isConfirmingPayment.value = true
  cardError.value = ''

  track('payment_initiated', {
    amount: immediatePaymentAmount.value,
    method: selectedPaymentMethod.value,
    has_trial: hasTrial.value,
  })

  try {
    let result

    if (selectedPaymentMethod.value === 'sepa_debit') {
      if (!ibanElement || !sepaName.value.trim() || !sepaEmail.value.trim()) {
        cardError.value = t('register.sepaFieldsRequired')

        return
      }
      result = await stripe.confirmSepaDebitSetup(pendingCheckout.value.client_secret, {
        payment_method: {
          sepa_debit: ibanElement,
          billing_details: { name: sepaName.value, email: sepaEmail.value },
        },
      })
    } else {
      if (!cardElement) return
      result = await stripe.confirmCardSetup(pendingCheckout.value.client_secret, {
        payment_method: { card: cardElement },
      })
    }

    if (result.error) {
      cardError.value = result.error.message
      track('payment_failed', { error: result.error.code || result.error.message, method: selectedPaymentMethod.value })

      return
    }

    await auth.confirmRegistrationPayment(result.setupIntent.payment_method, pendingCheckout.value.subscription_id)

    paymentSuccess.value = true

    track('payment_succeeded', {
      amount: immediatePaymentAmount.value,
      method: selectedPaymentMethod.value,
      subscription_id: pendingCheckout.value.subscription_id,
    })
    track('registration_completed', {
      plan: selectedPlan.value,
      interval: annualToggle.value ? 'yearly' : 'monthly',
      has_trial: hasTrial.value,
      addons_count: selectedAddons.value.length,
    })
    persistence.clear()

    runtime.teardown()
    await runtime.boot('company')
    toast(t('register.registrationSuccess'), 'success')
    await router.replace('/dashboard')
  }
  catch (e) {
    cardError.value = e?.data?.message || e?.message || t('register.paymentFailed')
    track('payment_failed', { error: e?.message || 'unknown', method: selectedPaymentMethod.value })
  }
  finally {
    isConfirmingPayment.value = false
  }
}

const onAddonToggled = ({ key, action, price }) => {
  track('addon_toggled', { addon_key: key, action, price })
}

const imageVariant = useGenerateImageVariant(authV2RegisterIllustrationLight, authV2RegisterIllustrationDark, authV2RegisterIllustrationBorderedLight, authV2RegisterIllustrationBorderedDark, true)
const authThemeMask = useGenerateImageVariant(authV2MaskLight, authV2MaskDark)
</script>

<template>
  <RouterLink to="/">
    <div class="auth-logo d-flex align-center gap-x-3">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
      <h1 class="auth-title">
        {{ themeConfig.app.title }}
      </h1>
    </div>
  </RouterLink>

  <VRow
    no-gutters
    class="auth-wrapper bg-surface auth-wrapper--split"
  >
    <VCol
      md="4"
      class="d-none d-md-flex auth-illustration-col"
    >
      <div class="position-relative bg-background w-100 me-0">
        <div
          class="d-flex align-center justify-center w-100 h-100"
          style="padding-inline: 50px;"
        >
          <VImg
            max-width="400"
            :src="imageVariant"
            class="auth-illustration mt-16 mb-2"
          />
        </div>

        <img
          class="auth-footer-mask flip-in-rtl"
          :src="authThemeMask"
          alt="auth-footer-mask"
          height="280"
          width="100"
        >
      </div>
    </VCol>

    <VCol
      cols="12"
      md="8"
      class="auth-card-v2 d-flex align-center justify-center pa-10 auth-form-col"
    >
      <VCard
        flat
        class="mt-12 mt-sm-10"
        :max-width="850"
      >
        <AppStepper
          v-model:current-step="currentStep"
          :items="stepItems"
          :direction="$vuetify.display.smAndUp ? 'horizontal' : 'vertical'"
          icon-size="22"
          class="stepper-icon-step-bg mb-8"
          :is-active-step-valid="canAdvance"
        />

        <VWindow
          v-model="currentStep"
          class="disable-tab-transition"
        >
          <!-- Step 0: Industry -->
          <VWindowItem>
            <RegisterStepIndustry
              v-model:selected-jobdomain="selectedJobdomain"
              :jobdomains="jobdomains"
              :loading="plansLoading"
            />
          </VWindowItem>

          <!-- Step 1: Plan -->
          <VWindowItem>
            <RegisterStepPlan
              v-model:selected-plan="selectedPlan"
              v-model:annual-toggle="annualToggle"
              :plans="plans"
              :preview-modules="previewModules"
            />
          </VWindowItem>

          <!-- Step 2: Addons -->
          <VWindowItem>
            <RegisterStepAddons
              v-model:selected-addons="selectedAddons"
              :addons="addons"
              :loading="addonsLoading"
              @addon-toggled="onAddonToggled"
            />
          </VWindowItem>

          <!-- Step 3: Company -->
          <VWindowItem>
            <RegisterStepCompany
              v-model:company-name="form.company_name"
              v-model:selected-market="selectedMarket"
              v-model:legal-status-key="legalStatusKey"
              v-model:dynamic-field-values="dynamicFieldValues"
              v-model:billing-is-same-as-company="billingIsSameAsCompany"
              :company-fields="companyFields"
              :markets="markets"
              :legal-statuses="legalStatuses"
              :markets-loading="marketsLoading"
              :legal-statuses-loading="legalStatusesLoading"
              :fields-loading="fieldsLoading"
              :market-dial-code="marketDialCode"
              :errors="errors"
            />
          </VWindowItem>

          <!-- Step 4: Account -->
          <VWindowItem>
            <RegisterStepAccount
              v-model:first-name="form.first_name"
              v-model:last-name="form.last_name"
              v-model:email="form.email"
              v-model:password="form.password"
              :error-message="errorMessage"
              :errors="errors"
              @clear-error="errorMessage = ''"
              @email-edited="emailManuallyEdited = true"
            />
          </VWindowItem>

          <!-- Step 5: Summary -->
          <VWindowItem>
            <RegisterStepSummary
              ref="summaryRef"
              :selected-jobdomain-label="selectedJobdomainLabel"
              :selected-plan-data="selectedPlanData"
              :annual-toggle="annualToggle"
              :display-price="displayPrice"
              :has-trial="hasTrial"
              :selected-addons="selectedAddons"
              :selected-addon-labels="selectedAddonLabels"
              :addons-total-price="addonsTotalPrice"
              :form="form"
              :legal-status-key="legalStatusKey"
              :selected-legal-status-label="selectedLegalStatusLabel"
              :selected-market-label="selectedMarketLabel"
              :selected-market-key="selectedMarket"
              :filled-dynamic-fields="filledDynamicFields"
              :has-cost="hasCost"
              :plan-price="planPrice"
              :total-monthly-price="totalMonthlyPrice"
              :immediate-payment-amount="immediatePaymentAmount"
              :trial-charge-immediate="trialChargeImmediate"
              :needs-payment="needsPayment"
              :error-message="errorMessage"
              :payment-phase="paymentPhase"
              :card-error="cardError"
              :payment-success="paymentSuccess"
              :is-confirming-payment="isConfirmingPayment"
              :is-loading="isLoading"
              :selected-payment-method="selectedPaymentMethod"
              :sepa-name="sepaName"
              :sepa-email="sepaEmail"
              @go-to-step="currentStep = $event"
              @submit="handleRegister"
              @confirm-payment="confirmPayment"
              @clear-error="errorMessage = ''"
              @clear-card-error="cardError = ''"
              @retry-payment="cardError = ''"
              @switch-payment-method="handleSwitchPaymentMethod"
              @update:sepa-name="sepaName = $event"
              @update:sepa-email="sepaEmail = $event"
            />
          </VWindowItem>
        </VWindow>

        <!-- Navigation buttons -->
        <div class="d-flex flex-wrap justify-space-between gap-x-4 mt-6">
          <VBtn
            color="secondary"
            :disabled="currentStep === 0"
            variant="tonal"
            @click="currentStep--"
          >
            <VIcon
              icon="tabler-arrow-left"
              start
              class="flip-in-rtl"
            />
            {{ t('register.previous') }}
          </VBtn>

          <VBtn
            v-if="currentStep < 5"
            :disabled="!canAdvance"
            @click="currentStep++"
          >
            {{ t('register.next') }}
            <VIcon
              icon="tabler-arrow-right"
              end
              class="flip-in-rtl"
            />
          </VBtn>
        </div>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth";

.auth-logo {
  position: fixed !important;
  z-index: 3;
}

.auth-wrapper--split {
  align-items: flex-start !important;
}

.auth-illustration-col {
  position: sticky !important;
  inset-block-start: 0;
  block-size: 100dvh;
  overflow: hidden;
}

.auth-form-col {
  min-block-size: 100dvh;
}

.plan-card {
  transition: border-color 0.2s ease;
}

.card-list {
  --v-card-list-gap: 0.5rem;
}

.stripe-card-element {
  background-color: rgb(var(--v-theme-surface));
  border-color: rgba(var(--v-border-color), var(--v-border-opacity)) !important;
  min-block-size: 44px;
  transition: border-color 0.2s ease;

  &:hover {
    border-color: rgba(var(--v-border-color), 0.6) !important;
  }
}
</style>
