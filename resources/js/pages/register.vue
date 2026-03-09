<script setup>
const { t, locale } = useI18n()

import { loadStripe } from '@stripe/stripe-js'
import DynamicFormRenderer from '@/core/components/DynamicFormRenderer.vue'
import { formatMoney } from '@/utils/money'
import { useAuthStore } from '@/core/stores/auth'
import { useRuntimeStore } from '@/core/runtime/runtime'
import { usePublicPlans } from '@/composables/usePublicPlans'
import { usePublicFields } from '@/composables/usePublicFields'
import { usePublicMarkets } from '@/composables/usePublicMarkets'
import { usePublicAddons } from '@/composables/usePublicAddons'
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import authV2RegisterIllustrationBorderedDark from '@images/pages/auth-v2-register-illustration-bordered-dark.png'
import authV2RegisterIllustrationBorderedLight from '@images/pages/auth-v2-register-illustration-bordered-light.png'
import authV2RegisterIllustrationDark from '@images/pages/auth-v2-register-illustration-dark.png'
import authV2RegisterIllustrationLight from '@images/pages/auth-v2-register-illustration-light.png'
import authV2MaskDark from '@images/pages/misc-mask-dark.png'
import authV2MaskLight from '@images/pages/misc-mask-light.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

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

// ─── Browser locale detection ────────────────────────────
function detectLocaleFromBrowser() {
  const lang = navigator.language?.split('-')[0] || 'fr'

  return ['fr', 'en'].includes(lang) ? lang : 'fr'
}

function detectMarketFromBrowser(availableMarkets) {
  const browserLang = navigator.language?.toLowerCase() || 'fr'

  // Try country code match (e.g., 'en-GB' → GB, 'fr-FR' → FR)
  const countryCode = browserLang.split('-')[1]?.toUpperCase()

  if (countryCode) {
    const match = availableMarkets.find(m => m.key === countryCode)

    if (match) return match.key
  }

  // Try language match (e.g., 'fr' → FR market with locale 'fr_FR')
  const lang = browserLang.split('-')[0]
  const langMatch = availableMarkets.find(m => m.locale?.startsWith(lang))

  if (langMatch) return langMatch.key

  // Fallback to default market
  return availableMarkets.find(m => m.is_default)?.key || availableMarkets[0]?.key
}

onMounted(() => {
  // Set locale immediately from browser language
  const browserLocale = detectLocaleFromBrowser()

  locale.value = browserLocale
  useCookie('language').value = browserLocale

  fetchPlans()
  fetchMarkets()
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
  password_confirmation: '',
  company_name: '',
})

const isPasswordVisible = ref(false)
const isLoading = ref(false)
const errorMessage = ref('')
const errors = ref({})

// ─── Stripe payment state (ADR-302) ─────────────────────
const paymentPhase = ref(false) // true = company created, awaiting card
const cardError = ref('')
const isConfirmingPayment = ref(false)
const paymentSuccess = ref(false)
let stripe = null
let cardElement = null
const cardElementRef = ref(null)
const pendingCheckout = ref(null) // { client_secret, publishable_key, subscription_id }

const needsPayment = computed(() => hasCost.value)

// ─── Field splits for step 3 ─────────────────────────────
const generalFields = computed(() =>
  companyFields.value.filter(f => f.group === 'general').map(({ group, ...rest }) => rest),
)

const addressFields = computed(() =>
  companyFields.value.filter(f => ['address', 'contact'].includes(f.group)).map(({ group, ...rest }) => rest),
)

const billingFields = computed(() =>
  companyFields.value.filter(f => f.group === 'billing' && f.code !== 'billing_email').map(({ group, ...rest }) => rest),
)

const billingEmailField = computed(() =>
  companyFields.value.filter(f => f.code === 'billing_email').map(({ group, ...rest }) => rest),
)

// ─── Reactive data fetching ──────────────────────────────
const languageCookie = useCookie('language')

watch([selectedJobdomain, selectedMarket], async ([jd, mk]) => {
  if (jd) {
    // Switch locale to market's language before fetching fields
    const market = markets.value.find(m => m.key === mk)
    const marketLocale = market?.locale?.substring(0, 2) || 'fr'

    locale.value = marketLocale
    languageCookie.value = marketLocale

    // Wait for cookie to sync before API call sends X-Locale header
    await nextTick()

    // Await fields then prune stale values (avoids blank flash)
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
    // Await legal statuses before resetting key (avoids blink)
    await fetchLegalStatuses(mk)
    if (!legalStatuses.value.find(ls => ls.key === legalStatusKey.value))
      legalStatusKey.value = null
  }
})

watch([selectedJobdomain, selectedPlan], ([jd, plan]) => {
  if (jd && plan)
    fetchPreview(jd, plan)
})

watch([selectedJobdomain, selectedPlan, selectedMarket], ([jd, plan, mk]) => {
  if (jd && plan)
    fetchAddons(jd, plan, mk)
  selectedAddons.value = []
})

// Auto-select market from browser language detection
watch(markets, mks => {
  if (!mks.length) return

  if (!selectedMarket.value)
    selectedMarket.value = detectMarketFromBrowser(mks)
})

// Pre-fill account email from billing_email
const emailManuallyEdited = ref(false)

watch(() => dynamicFieldValues.value.billing_email, email => {
  if (email && !emailManuallyEdited.value)
    form.value.email = email
})

// ─── Addon helpers ───────────────────────────────────────
const toggleAddon = key => {
  const idx = selectedAddons.value.indexOf(key)

  if (idx >= 0)
    selectedAddons.value.splice(idx, 1)
  else
    selectedAddons.value.push(key)
}

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

// ─── Computed helpers ────────────────────────────────────
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

// Amount due NOW at end of tunnel
// - No trial: plan + addons
// - Trial + charge immediate: plan + addons
// - Trial + charge end_of_trial: addons only
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

const legalStatusItems = computed(() => {
  return legalStatuses.value.map(ls => ({
    title: ls.description ? `${ls.name} — ${ls.description}` : ls.name,
    value: ls.key,
  }))
})

// Filled dynamic fields for summary
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
    case 2: return true // addons are optional
    case 3: return !!form.value.company_name && !!selectedMarket.value
    case 4: return !!(form.value.first_name && form.value.last_name && form.value.email && form.value.password && form.value.password_confirmation)
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
      password_confirmation: form.value.password_confirmation,
      company_name: form.value.company_name,
      jobdomain_key: selectedJobdomain.value || undefined,
      plan_key: selectedPlan.value || undefined,
      billing_interval: annualToggle.value ? 'yearly' : 'monthly',
      market_key: selectedMarket.value || undefined,
      legal_status_key: legalStatusKey.value || undefined,
      dynamic_fields: dynamicFieldValues.value,
      addon_keys: selectedAddons.value.length > 0 ? selectedAddons.value : undefined,
      billing_same_as_company: billingIsSameAsCompany.value,
    })

    // ADR-302: Embedded payment — mount Stripe Card Element
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

    // Free or trial plan — go straight to dashboard
    runtime.teardown()
    await runtime.boot('company')
    await router.replace('/dashboard')
  }
  catch (error) {
    if (error?.data?.errors)
      errors.value = error.data.errors

    errorMessage.value = error?.data?.message || t('auth.registrationFailed')

    // Jump back to account step if server validation fails
    if (errors.value.email || errors.value.password || errors.value.first_name || errors.value.last_name)
      currentStep.value = 4
  }
  finally {
    isLoading.value = false
  }
}

// ─── Stripe Payment (ADR-302) ───────────────────────────
const selectedPaymentMethod = ref('card') // 'card' or 'sepa_debit'
let ibanElement = null
const ibanElementRef = ref(null)
const sepaName = ref('')
const sepaEmail = ref('')

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

  cardElement.mount(cardElementRef.value)
}

const switchToSepa = async () => {
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
  ibanElement.mount(ibanElementRef.value)
}

const switchToCard = async () => {
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
  cardElement.mount(cardElementRef.value)
}

const confirmPayment = async () => {
  if (!stripe || !pendingCheckout.value?.client_secret) return

  isConfirmingPayment.value = true
  cardError.value = ''

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

      return
    }

    // Save the payment method + activate subscription (or keep trialing)
    await auth.confirmRegistrationPayment(result.setupIntent.payment_method, pendingCheckout.value.subscription_id)

    paymentSuccess.value = true

    // Navigate to dashboard
    runtime.teardown()
    await runtime.boot('company')
    await router.replace('/dashboard')
  }
  catch (e) {
    cardError.value = e?.data?.message || e?.message || t('register.paymentFailed')
  }
  finally {
    isConfirmingPayment.value = false
  }
}

const retryPayment = () => {
  cardError.value = ''
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
          <!-- ─── Step 0: Industry ───────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.whatsYourIndustry') }}
            </h4>
            <p class="text-body-1 mb-6">
              {{ t('register.industryDescription') }}
            </p>

            <VSkeletonLoader
              v-if="plansLoading"
              type="card"
            />

            <template v-else>
              <CustomRadiosWithIcon
                v-model:selected-radio="selectedJobdomain"
                :radio-content="jobdomains.map(jd => ({
                  title: jd.label,
                  desc: jd.description || '',
                  value: jd.key,
                  icon: { icon: 'tabler-briefcase', size: '28' },
                }))"
                :grid-column="{ sm: '4', cols: '12' }"
              >
                <template #default="{ item }">
                  <div class="text-center">
                    <VIcon
                      icon="tabler-briefcase"
                      size="36"
                      class="mb-2 text-primary"
                    />
                    <h5 class="text-h5 mb-2">
                      {{ item.title }}
                    </h5>
                    <p class="clamp-text mb-0 text-body-2">
                      {{ item.desc }}
                    </p>
                  </div>
                </template>
              </CustomRadiosWithIcon>

              <p
                v-if="jobdomains.length === 0"
                class="text-body-1 text-disabled"
              >
                {{ t('register.noIndustriesAvailable') }}
              </p>
            </template>
          </VWindowItem>

          <!-- ─── Step 1: Plan ──────────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.choosePlan') }}
            </h4>
            <p class="text-body-1 mb-4">
              {{ t('register.allPlansInclude') }}
            </p>

            <!-- Monthly/Annual toggle -->
            <div class="d-flex font-weight-medium text-body-1 align-center justify-center mb-6">
              <VLabel
                for="plan-toggle"
                class="me-3"
              >
                {{ t('common.monthly') }}
              </VLabel>
              <VSwitch
                id="plan-toggle"
                v-model="annualToggle"
              >
                <template #label>
                  <div class="text-body-1 font-weight-medium">
                    {{ t('common.annually') }}
                  </div>
                </template>
              </VSwitch>
            </div>

            <VRow>
              <VCol
                v-for="plan in plans"
                :key="plan.key"
                cols="12"
                sm="4"
              >
                <VCard
                  flat
                  border
                  :class="[
                    'cursor-pointer plan-card',
                    selectedPlan === plan.key ? 'border-primary border-opacity-100' : '',
                  ]"
                  @click="selectedPlan = plan.key"
                >
                  <VCardText
                    style="block-size: 2.5rem;"
                    class="text-end"
                  >
                    <VChip
                      v-if="plan.is_popular"
                      label
                      color="primary"
                      size="small"
                    >
                      {{ t('common.popular') }}
                    </VChip>
                  </VCardText>

                  <VCardText class="text-center">
                    <h5 class="text-h5 mb-1">
                      {{ plan.name }}
                    </h5>
                    <p class="text-body-2 mb-4">
                      {{ plan.description }}
                    </p>

                    <div class="d-flex justify-center align-baseline pb-4">
                      <span class="text-h3 font-weight-medium text-primary">
                        {{ formatMoney(annualToggle ? Math.round(plan.price_yearly / 12 * 100) : plan.price_monthly * 100) }}
                      </span>
                      <span class="text-body-1 font-weight-medium">{{ t('common.perMonth') }}</span>
                    </div>

                    <VList
                      density="compact"
                      class="card-list"
                    >
                      <VListItem
                        v-for="feature in plan.feature_labels"
                        :key="feature"
                      >
                        <template #prepend>
                          <VIcon
                            size="8"
                            icon="tabler-circle-filled"
                            color="rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity))"
                          />
                        </template>
                        <VListItemTitle class="text-body-2">
                          {{ feature }}
                        </VListItemTitle>
                      </VListItem>
                    </VList>

                    <!-- ADR-287: Trial badge -->
                    <VChip
                      v-if="plan.trial_days > 0"
                      color="info"
                      variant="tonal"
                      size="small"
                      prepend-icon="tabler-clock"
                      class="mt-2"
                    >
                      {{ t('companyPlan.freeTrialDays', { days: plan.trial_days }) }}
                    </VChip>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <!-- Module preview -->
            <div
              v-if="previewModules.length > 0"
              class="mt-4"
            >
              <p class="text-body-2 font-weight-medium mb-2">
                {{ t('register.includedModules') }}
              </p>
              <div class="d-flex flex-wrap gap-2">
                <VChip
                  v-for="mod in previewModules"
                  :key="mod.key"
                  size="small"
                  :color="mod.source === 'core' ? 'secondary' : 'primary'"
                >
                  {{ mod.name }}
                </VChip>
              </div>
            </div>
          </VWindowItem>

          <!-- ─── Step 2: Addons ────────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.addonsTitle') }}
            </h4>
            <p class="text-body-1 mb-6">
              {{ t('register.addonsDescription') }}
            </p>

            <VSkeletonLoader
              v-if="addonsLoading"
              type="card, card"
            />

            <template v-else-if="addons.length > 0">
              <VCard
                v-for="addon in addons"
                :key="addon.key"
                flat
                border
                class="mb-3 cursor-pointer"
                :class="selectedAddons.includes(addon.key) ? 'border-primary border-opacity-100' : ''"
                @click="toggleAddon(addon.key)"
              >
                <VCardText class="d-flex align-center justify-space-between">
                  <div>
                    <h6 class="text-h6">
                      {{ addon.name }}
                    </h6>
                    <p class="text-body-2 text-medium-emphasis mb-0">
                      {{ addon.description }}
                    </p>
                  </div>
                  <div class="d-flex align-center gap-3">
                    <span class="text-body-1 font-weight-medium">
                      {{ formatMoney(addon.price) }}{{ t('common.perMonth') }}
                    </span>
                    <VSwitch
                      :model-value="selectedAddons.includes(addon.key)"
                      @update:model-value="toggleAddon(addon.key)"
                    />
                  </div>
                </VCardText>
              </VCard>

              <div
                v-if="addonsTotalPrice > 0"
                class="text-end mt-4"
              >
                <span class="text-body-1">{{ t('register.addonsTotal') }} :</span>
                <span class="text-h6 text-primary ms-2">
                  {{ formatMoney(addonsTotalPrice) }}{{ t('common.perMonth') }}
                </span>
              </div>
            </template>

            <VAlert
              v-else
              type="info"
              variant="tonal"
            >
              {{ t('register.noAddonsAvailable') }}
            </VAlert>
          </VWindowItem>

          <!-- ─── Step 3: Company ───────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.companyDetails') }}
            </h4>
            <p class="text-body-1 mb-6">
              {{ t('register.companyDetailsDesc') }}
            </p>

            <!-- Company name + Country + Legal status -->
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.company_name"
                  :label="t('register.companyName')"
                  :placeholder="t('register.companyNamePlaceholder')"
                  :error-messages="errors.company_name"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppSelect
                  v-model="selectedMarket"
                  :label="t('register.country')"
                  :items="markets.map(mk => ({ title: mk.name, value: mk.key }))"
                  :placeholder="t('register.selectCountry')"
                  :loading="marketsLoading"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppSelect
                  v-model="legalStatusKey"
                  :label="t('register.legalStatus')"
                  :items="legalStatusItems"
                  :placeholder="t('register.selectLegalStatus')"
                  :disabled="!selectedMarket"
                  :loading="legalStatusesLoading"
                  clearable
                />
              </VCol>
            </VRow>

            <!-- General fields (siret, vat_number, legal_name) -->
            <VSkeletonLoader
              v-if="fieldsLoading"
              type="text, text"
              class="mt-2"
            />
            <template v-else>
              <VRow v-if="generalFields.length > 0">
                <DynamicFormRenderer
                  v-model="dynamicFieldValues"
                  :fields="generalFields"
                  :cols="6"
                  :dial-code="marketDialCode"
                />
              </VRow>

              <!-- Company address + contact -->
              <template v-if="addressFields.length > 0">
                <VDivider class="my-4" />
                <h6 class="text-h6 mb-2">
                  {{ t('register.companyAddress') }}
                </h6>
                <VRow>
                  <DynamicFormRenderer
                    v-model="dynamicFieldValues"
                    :fields="addressFields"
                    :cols="6"
                    :dial-code="marketDialCode"
                  />
                </VRow>
              </template>

              <!-- Billing address toggle -->
              <VDivider class="my-4" />
              <h6 class="text-h6 mb-2">
                {{ t('register.billingAddress') }}
              </h6>
              <VSwitch
                v-model="billingIsSameAsCompany"
                :label="t('register.billingSameAsCompany')"
                class="mb-2"
              />

              <!-- Billing address fields (conditional) -->
              <VRow v-if="!billingIsSameAsCompany && billingFields.length > 0">
                <DynamicFormRenderer
                  v-model="dynamicFieldValues"
                  :fields="billingFields"
                  :cols="6"
                  :dial-code="marketDialCode"
                />
              </VRow>

              <!-- Billing email (always visible) -->
              <VRow v-if="billingEmailField.length > 0">
                <DynamicFormRenderer
                  v-model="dynamicFieldValues"
                  :fields="billingEmailField"
                  :cols="6"
                  :dial-code="marketDialCode"
                />
              </VRow>
            </template>
          </VWindowItem>

          <!-- ─── Step 4: Account ───────────────────────── -->
          <VWindowItem>
            <h4 class="text-h4 mb-1">
              {{ t('register.createYourAccount') }}
            </h4>
            <p class="text-body-1 mb-6">
              {{ t('register.enterDetails') }}
            </p>

            <VAlert
              v-if="errorMessage"
              type="error"
              class="mb-6"
              closable
              @click:close="errorMessage = ''"
            >
              {{ errorMessage }}
            </VAlert>

            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.first_name"
                  autofocus
                  :label="t('register.firstName')"
                  placeholder="John"
                  :error-messages="errors.first_name"
                />
              </VCol>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.last_name"
                  :label="t('register.lastName')"
                  placeholder="Doe"
                  :error-messages="errors.last_name"
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="form.email"
                  :label="t('auth.email')"
                  type="email"
                  placeholder="johndoe@email.com"
                  :error-messages="errors.email"
                  @input="emailManuallyEdited = true"
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="form.password"
                  :label="t('auth.password')"
                  placeholder="············"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="new-password"
                  :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  :error-messages="errors.password"
                  @click:append-inner="isPasswordVisible = !isPasswordVisible"
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="form.password_confirmation"
                  :label="t('auth.confirmPassword')"
                  placeholder="············"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="new-password"
                />
              </VCol>
            </VRow>
          </VWindowItem>

          <!-- ─── Step 5: Summary ───────────────────────── -->
          <VWindowItem>
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
              @click:close="errorMessage = ''"
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
                  @click="currentStep = 0"
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
                  @click="currentStep = 1"
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
                  @click="currentStep = 2"
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
                  @click="currentStep = 3"
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
                  @click="currentStep = 4"
                >
                  <VIcon
                    icon="tabler-pencil"
                    start
                  />
                  {{ t('register.edit') }}
                </VBtn>
              </VCardText>
            </VCard>

            <!-- Pricing breakdown -->
            <VCard
              v-if="hasCost"
              flat
              border
              class="mb-6"
              color="primary"
              variant="tonal"
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

                <!-- Trial badge -->
                <VChip
                  v-if="hasTrial"
                  color="success"
                  size="small"
                  class="mt-1 mb-2"
                >
                  {{ t('register.trialBadge', { days: selectedPlanData?.trial_days }) }}
                </VChip>

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

                <!-- Total -->
                <VDivider class="my-2" />
                <div class="d-flex justify-space-between align-center">
                  <h6 class="text-h6">
                    {{ t('register.totalMonthly') }}
                  </h6>
                  <h5 class="text-h5 font-weight-bold">
                    {{ formatMoney(totalMonthlyPrice) }}{{ t('common.perMonth') }}
                  </h5>
                </div>

                <!-- Due now vs deferred (only for trial with end_of_trial timing and addons) -->
                <template v-if="hasTrial && !trialChargeImmediate && addonsTotalPrice > 0">
                  <VDivider class="my-2" />
                  <div class="d-flex justify-space-between align-center text-success">
                    <span class="text-body-2">{{ t('register.dueNow') }}</span>
                    <span class="text-body-1 font-weight-bold">{{ formatMoney(addonsTotalPrice) }}</span>
                  </div>
                  <div class="d-flex justify-space-between align-center text-medium-emphasis">
                    <span class="text-body-2">{{ t('register.dueAfterTrial', { days: selectedPlanData?.trial_days }) }}</span>
                    <span class="text-body-2">{{ formatMoney(planPrice) }}{{ t('common.perMonth') }}</span>
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
                @click:close="cardError = ''"
              >
                {{ cardError }}
                <template #append>
                  <VBtn
                    variant="text"
                    size="small"
                    @click="retryPayment"
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

              <!-- Payment method selector (Card + SEPA for trials) -->
              <VRadioGroup
                v-if="hasTrial"
                v-model="selectedPaymentMethod"
                inline
                class="mb-4"
              >
                <VRadio
                  value="card"
                  @click="switchToCard"
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
                  @click="switchToSepa"
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
                  v-model="sepaName"
                  :label="t('register.sepaHolderName')"
                  class="mb-4"
                />
                <AppTextField
                  v-model="sepaEmail"
                  :label="t('register.sepaEmail')"
                  type="email"
                  class="mb-4"
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
                color="primary"
                :loading="isConfirmingPayment"
                :disabled="paymentSuccess"
                @click="confirmPayment"
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
            </template>

            <!-- Pre-payment: Submit button -->
            <template v-else>
              <VBtn
                block
                :loading="isLoading"
                @click="handleRegister"
              >
                {{ needsPayment ? t('register.proceedToPayment') : t('register.createAccountBtn') }}
              </VBtn>
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
          </VWindowItem>
        </VWindow>

        <!-- ─── Navigation buttons ───────────────────── -->
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
