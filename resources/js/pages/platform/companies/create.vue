<script setup>
/**
 * ADR-834 — Platform « New company » wizard (preset wizard-examples/create-deal : vertical AppStepper + VWindow).
 * Every choice comes from the server (options / preview) and the server re-validates everything at creation :
 * single activity, plan rights, dependencies, no payment, secure administrator invitation, idempotent submit.
 */
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { usePublicFields } from '@/composables/usePublicFields'
import { usePublicMarkets } from '@/composables/usePublicMarkets'
import { useAppToast } from '@/composables/useAppToast'
import { useCatalogText } from '@/composables/useCatalogText'
import RegisterStepCompany from '@/pages/register/_RegisterStepCompany.vue'
import { companyFieldIssues, keepSelectable, overrideApplies, overrideErrors, overridePayload, paidOptionsBlocked, subscriptionOutcome as subscriptionOutcomeRule } from '@/modules/platform-admin/companies/companyCreationRules'
import CreateStepActivity from './_CreateStepActivity.vue'
import CreateStepModules from './_CreateStepModules.vue'
import CreateStepSubscription from './_CreateStepSubscription.vue'
import CreateStepAdministrator from './_CreateStepAdministrator.vue'
import CreateStepInitialization from './_CreateStepInitialization.vue'
import CreateStepSummary from './_CreateStepSummary.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.companies',
    permission: 'create_companies', // = POST /platform/companies
    navActiveKey: 'platform-companies',
  },
})

const { t } = useI18n()
const router = useRouter()
const store = usePlatformCompaniesStore()
const { toast } = useAppToast()
const { t: tr, te } = useI18n()
const { moduleName } = useCatalogText()

// module texts in the interface language (ADR-806 catalogue), manifest text otherwise
const moduleDescription = (key, fallback) => {
  const k = `moduleCatalog.${String(key).replace(/\./g, '_')}.description`

  return te(k) ? tr(k) : fallback
}

const localizeModules = list => list.map(m => ({
  ...m,
  name: moduleName(m.key, m.name),
  description: moduleDescription(m.key, m.description),
  requires: m.requires.map(r => ({ ...r, name: moduleName(r.key, r.name) })),
}))

const { fields: companyFields, loading: fieldsLoading, fetchFields } = usePublicFields()
const { markets, legalStatuses, loading: marketsLoading, legalStatusesLoading, fetchMarkets, fetchLegalStatuses } = usePublicMarkets()

const STEP_KEYS = ['activity', 'company', 'modules', 'subscription', 'administrator', 'initialization', 'summary']
const STEP_ICONS = ['tabler-briefcase', 'tabler-building', 'tabler-puzzle', 'tabler-credit-card', 'tabler-user-shield', 'tabler-adjustments', 'tabler-checklist']

const stepItems = computed(() => STEP_KEYS.map((key, i) => ({
  title: t(`platformCompanyCreation.steps.${key}.title`),
  subtitle: t(`platformCompanyCreation.steps.${key}.subtitle`),
  icon: STEP_ICONS[i],
})))

const currentStep = ref(0)
const idempotencyKey = crypto.randomUUID() // one wizard = one company, whatever the number of clicks
const submitting = ref(false)
const serverErrors = ref({})

const options = ref({ jobdomains: [], plans: [], can_override: false })
const optionsLoading = ref(true)
const preview = ref({ modules: [], initialization: null })
const previewLoading = ref(false)
const administratorLookup = ref(null)
const lookupLoading = ref(false)
const removedAfterPlanChange = ref([])

const form = reactive({
  company: { name: '', market_key: null, legal_status_key: null, dynamic_fields: {}, billing_same_as_company: true },
  jobdomain_key: null,
  module_keys: [],
  subscription: { plan_key: null, annual: false, overrideEnabled: false, override: { type: 'trial', trial_days: 30, complimentary_months: 3, reason: '' } },
  administrator: { first_name: '', last_name: '', email: '' },
  initialization: { planning: false, organization: false },
})

onMounted(async () => {
  try {
    const [opts] = await Promise.all([store.fetchCreationOptions(), fetchMarkets()])

    options.value = opts
    if (opts.jobdomains.length === 1)
      form.jobdomain_key = opts.jobdomains[0].key
    form.subscription.plan_key = (opts.plans.find(p => p.is_popular) || opts.plans.find(p => !p.is_free) || opts.plans[0])?.key ?? null
  }
  catch {
    toast(t('platformCompanyCreation.errors.loadOptions'), 'error')
  }
  finally {
    optionsLoading.value = false
  }
})

watch(markets, list => {
  if (!form.company.market_key && list.length)
    form.company.market_key = (list.find(m => m.is_default) || list[0]).key
}, { immediate: true })

watch(() => form.company.market_key, mk => {
  form.company.legal_status_key = null
  fetchLegalStatuses(mk)
})

watch(() => [form.jobdomain_key, form.company.market_key], ([jd, mk]) => {
  if (jd)
    fetchFields(jd, mk).catch(() => {})
})

watch(() => form.jobdomain_key, (jd, old) => {
  if (old && jd !== old)
    form.module_keys = []
})

watch(() => [form.jobdomain_key, form.subscription.plan_key, form.company.market_key], async ([jd, plan, mk]) => {
  if (!jd || !plan)
    return
  previewLoading.value = true
  try {
    const data = await store.fetchCreationPreview(jd, plan, mk)

    preview.value = { ...data, modules: localizeModules(data.modules) }

    // a plan change may make a selected module unavailable : drop it and say so
    const { kept, dropped } = keepSelectable(form.module_keys, preview.value.modules)

    removedAfterPlanChange.value = dropped
    form.module_keys = kept
  }
  catch {
    toast(t('platformCompanyCreation.errors.loadPreview'), 'error')
  }
  finally {
    previewLoading.value = false
  }
})

let lookupTimer = null
watch(() => form.administrator.email, email => {
  clearTimeout(lookupTimer)
  administratorLookup.value = null
  if (!/^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)+$/.test(email || ''))
    return
  lookupLoading.value = true
  lookupTimer = setTimeout(async () => {
    try {
      administratorLookup.value = await store.lookupAdministrator(email.trim())
    }
    catch {
      administratorLookup.value = null
    }
    finally {
      lookupLoading.value = false
    }
  }, 400)
})

// ── Derived business state ──────────────────────────────────────────────
const selectedJobdomain = computed(() => options.value.jobdomains.find(j => j.key === form.jobdomain_key) || null)
const selectedPlan = computed(() => options.value.plans.find(p => p.key === form.subscription.plan_key) || null)
const selectedMarket = computed(() => markets.value.find(m => m.key === form.company.market_key) || null)
const interval = computed(() => form.subscription.annual ? 'yearly' : 'monthly')

const subscriptionOutcome = computed(() => subscriptionOutcomeRule({
  plan: selectedPlan.value,
  canOverride: options.value.can_override,
  overrideEnabled: form.subscription.overrideEnabled,
  override: form.subscription.override,
  trialUsed: !!administratorLookup.value?.trial_used,
}))

const paidBlocked = computed(() => paidOptionsBlocked(form.module_keys, preview.value.modules, subscriptionOutcome.value))

// the activity's company fields (e.g. SIRET) : required, length and format checked HERE, as the server does —
// never discovered only at the end
const companyFieldMessages = computed(() => {
  const issues = companyFieldIssues(companyFields.value, form.company.dynamic_fields)
  const fmt = (key, list) => list.length ? [t(`platformCompanyCreation.company.${key}`, { fields: list.join(', ') })] : []

  return [...fmt('requiredMissing', issues.missing), ...fmt('tooLong', issues.tooLong), ...fmt('invalidFormat', issues.invalid)]
})

// The override form is validated ONLY when it applies (permission + switch + paid plan) : a hidden override
// (free plan selected after enabling it) never blocks « Next » and is never sent.
const overrideState = computed(() => ({
  plan: selectedPlan.value,
  canOverride: options.value.can_override,
  overrideEnabled: form.subscription.overrideEnabled,
  override: form.subscription.override,
}))

const overrideActive = computed(() => overrideApplies(overrideState.value))
const overrideFieldErrors = computed(() => overrideActive.value ? overrideErrors(form.subscription.override) : {})
const overrideValid = computed(() => Object.keys(overrideFieldErrors.value).length === 0)

// a free plan has no commercial terms : leaving a paid plan clears the override decision (explicit again if needed)
watch(selectedPlan, plan => {
  if (plan?.is_free)
    form.subscription.overrideEnabled = false
})

const emailValid = computed(() => /^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)+$/.test(form.administrator.email || ''))

const stepValid = computed(() => [
  !!form.jobdomain_key,
  !!form.company.name.trim() && !!form.company.market_key && !!form.jobdomain_key && !fieldsLoading.value && companyFieldMessages.value.length === 0,
  !previewLoading.value,
  !!form.subscription.plan_key && overrideValid.value,
  !!form.administrator.first_name.trim() && !!form.administrator.last_name.trim() && emailValid.value && !lookupLoading.value,
  true,
])

const allValid = computed(() => stepValid.value.every(Boolean) && !paidBlocked.value)
const canAdvance = computed(() => currentStep.value === STEP_KEYS.length - 1 ? allValid.value : stepValid.value[currentStep.value])

const goTo = step => {
  currentStep.value = step
}

// « Next » is never a silent dead end : it stays clickable ; on an incomplete step it says what is missing,
// marks the step and brings the operator to the explanation (business rules unchanged).
const attemptedSteps = ref([])
const stepAttempted = computed(() => attemptedSteps.value.includes(currentStep.value))
const issuesAlert = ref(null)

const stepIssues = computed(() => {
  const k = key => t(`platformCompanyCreation.issues.${key}`)
  const a = form.administrator

  return [
    [!form.jobdomain_key && k('activity')],
    [
      !form.jobdomain_key && k('activity'),
      !form.company.name.trim() && k('companyName'),
      !form.company.market_key && k('market'),
      form.jobdomain_key && fieldsLoading.value && k('fieldsLoading'),
      ...(form.jobdomain_key && !fieldsLoading.value ? companyFieldMessages.value : []),
    ],
    [previewLoading.value && k('modulesLoading')],
    [
      !form.subscription.plan_key && k('plan'),
      overrideFieldErrors.value.reason && t('platformCompanyCreation.subscription.override.errors.reason'),
      overrideFieldErrors.value.duration && t('platformCompanyCreation.subscription.override.errors.duration', overrideFieldErrors.value.duration),
    ],
    [
      !a.first_name.trim() && k('adminFirstName'),
      !a.last_name.trim() && k('adminLastName'),
      !emailValid.value && k('adminEmail'),
      emailValid.value && lookupLoading.value && k('adminChecking'),
    ],
    [],
  ].map(list => list.filter(Boolean))
})

const currentIssues = computed(() => stepIssues.value[currentStep.value] || [])

const next = async () => {
  if (currentStep.value >= STEP_KEYS.length - 1)
    return
  if (canAdvance.value) {
    currentStep.value++

    return
  }
  if (!attemptedSteps.value.includes(currentStep.value))
    attemptedSteps.value = [...attemptedSteps.value, currentStep.value]
  await nextTick()
  issuesAlert.value?.$el?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })
}

// ── Submit (idempotent) ─────────────────────────────────────────────────
const submit = async () => {
  if (submitting.value || !allValid.value)
    return
  submitting.value = true
  serverErrors.value = {}

  const s = form.subscription
  try {
    const res = await store.createCompany({
      idempotency_key: idempotencyKey,
      company: {
        name: form.company.name.trim(),
        market_key: form.company.market_key,
        legal_status_key: form.company.legal_status_key || null,
        dynamic_fields: Object.fromEntries(Object.entries(form.company.dynamic_fields).filter(([, v]) => v !== null && String(v).trim() !== '')),
        billing_same_as_company: form.company.billing_same_as_company,
      },
      jobdomain_key: form.jobdomain_key,
      module_keys: form.module_keys,
      subscription: {
        plan_key: s.plan_key,
        interval: interval.value,
        override: overridePayload(overrideState.value),
      },
      administrator: { ...form.administrator, email: form.administrator.email.trim() },
      initialization: form.initialization,
    })

    toast(res.replayed ? t('platformCompanyCreation.result.replayed') : t('platformCompanyCreation.result.created', { name: res.company.name }), 'success')
    await router.push({ name: 'platform-companies-id', params: { id: res.company.id, tab: 'onboarding' } })
  }
  catch (error) {
    serverErrors.value = error?.data?.errors || {}
    toast(error?.data?.message || t('platformCompanyCreation.errors.generic'), 'error')
    submitting.value = false
  }
}
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">
          {{ t('platformCompanyCreation.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('platformCompanyCreation.subtitle') }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        color="secondary"
        prepend-icon="tabler-arrow-left"
        :to="{ name: 'platform-companies' }"
      >
        {{ t('platformCompanyCreation.backToList') }}
      </VBtn>
    </div>

    <VCard>
      <VRow no-gutters>
        <VCol
          cols="12"
          md="4"
          lg="3"
          :class="$vuetify.display.mdAndUp ? 'border-e' : 'border-b'"
        >
          <VCardText>
            <AppStepper
              v-model:current-step="currentStep"
              :direction="$vuetify.display.mdAndUp ? 'vertical' : 'horizontal'"
              :items="stepItems"
              icon-size="22"
              class="stepper-icon-step-bg"
              :is-active-step-valid="canAdvance || !stepAttempted"
            />
          </VCardText>
        </VCol>

        <VCol
          cols="12"
          md="8"
          lg="9"
        >
          <VCardText>
            <VSkeletonLoader
              v-if="optionsLoading"
              type="article, actions"
            />
            <VWindow
              v-else
              v-model="currentStep"
              class="disable-tab-transition"
              :touch="false"
            >
              <!-- A — Activity -->
              <VWindowItem>
                <CreateStepActivity
                  v-model:selected-jobdomain="form.jobdomain_key"
                  :jobdomains="options.jobdomains"
                  :modules="preview.modules"
                  :loading="previewLoading"
                  :has-selected-modules="form.module_keys.length > 0"
                />
              </VWindowItem>

              <!-- B — Company (after the activity : its required fields, e.g. SIRET, depend on it) -->
              <VWindowItem>
                <p class="text-body-1">
                  {{ t('platformCompanyCreation.company.intro') }}
                </p>
                <VAlert
                  v-if="!form.jobdomain_key"
                  type="info"
                  variant="tonal"
                  class="mb-4"
                >
                  {{ t('platformCompanyCreation.company.chooseActivityFirst') }}
                </VAlert>
                <RegisterStepCompany
                  v-model:company-name="form.company.name"
                  v-model:selected-market="form.company.market_key"
                  v-model:legal-status-key="form.company.legal_status_key"
                  v-model:dynamic-field-values="form.company.dynamic_fields"
                  v-model:billing-is-same-as-company="form.company.billing_same_as_company"
                  :company-fields="form.jobdomain_key ? companyFields : []"
                  :markets="markets"
                  :legal-statuses="legalStatuses"
                  :markets-loading="marketsLoading"
                  :legal-statuses-loading="legalStatusesLoading"
                  :fields-loading="fieldsLoading"
                  :market-dial-code="selectedMarket?.dial_code || '+33'"
                  :errors="{ company_name: serverErrors['company.name'] || [] }"
                  i18n-prefix="platformCompanyCreation.form"
                />
                <VAlert
                  v-if="form.jobdomain_key && companyFieldMessages.length"
                  type="warning"
                  variant="tonal"
                  class="mt-4"
                >
                  <div
                    v-for="msg in companyFieldMessages"
                    :key="msg"
                  >
                    {{ msg }}
                  </div>
                </VAlert>
              </VWindowItem>

              <!-- C — Modules -->
              <VWindowItem>
                <CreateStepModules
                  v-model:selected="form.module_keys"
                  :modules="preview.modules"
                  :loading="previewLoading"
                  :plan-name="selectedPlan?.name || ''"
                  :paid-blocked="paidBlocked"
                  :removed-after-plan-change="removedAfterPlanChange"
                  :currency="selectedMarket?.currency || 'EUR'"
                />
              </VWindowItem>

              <!-- D — Subscription -->
              <VWindowItem>
                <CreateStepSubscription
                  v-model:plan-key="form.subscription.plan_key"
                  v-model:annual="form.subscription.annual"
                  v-model:override-enabled="form.subscription.overrideEnabled"
                  v-model:override="form.subscription.override"
                  :plans="options.plans"
                  :outcome="subscriptionOutcome"
                  :can-override="options.can_override"
                  :override-errors="attemptedSteps.includes(3) ? overrideFieldErrors : {}"
                  :paid-blocked="paidBlocked"
                />
              </VWindowItem>

              <!-- E — Administrator -->
              <VWindowItem>
                <CreateStepAdministrator
                  v-model:first-name="form.administrator.first_name"
                  v-model:last-name="form.administrator.last_name"
                  v-model:email="form.administrator.email"
                  :lookup="administratorLookup"
                  :checking="lookupLoading"
                  :errors="serverErrors"
                />
              </VWindowItem>

              <!-- F — Initialization -->
              <VWindowItem>
                <CreateStepInitialization
                  v-model:planning="form.initialization.planning"
                  v-model:organization="form.initialization.organization"
                  :initialization="preview.initialization"
                  :loading="previewLoading"
                />
              </VWindowItem>

              <!-- G — Summary -->
              <VWindowItem>
                <CreateStepSummary
                  :form="form"
                  :jobdomain="selectedJobdomain"
                  :plan="selectedPlan"
                  :market="selectedMarket"
                  :legal-statuses="legalStatuses"
                  :modules="preview.modules"
                  :initialization="preview.initialization"
                  :outcome="subscriptionOutcome"
                  :lookup="administratorLookup"
                  :company-field-messages="companyFieldMessages"
                  :paid-blocked="paidBlocked"
                  :server-errors="serverErrors"
                  @go-to="goTo"
                />
              </VWindowItem>
            </VWindow>

            <VAlert
              v-if="!optionsLoading && stepAttempted && currentIssues.length"
              ref="issuesAlert"
              type="warning"
              variant="tonal"
              class="mt-6"
              role="alert"
              :title="t('platformCompanyCreation.issues.title')"
              data-testid="step-issues"
            >
              <ul class="ps-4 mb-0">
                <li
                  v-for="(issue, i) in currentIssues"
                  :key="i"
                >
                  {{ issue }}
                </li>
              </ul>
            </VAlert>

            <div
              v-if="!optionsLoading"
              class="d-flex flex-wrap gap-4 justify-space-between mt-8"
            >
              <VBtn
                color="secondary"
                variant="tonal"
                :disabled="currentStep === 0 || submitting"
                @click="currentStep--"
              >
                <VIcon
                  icon="tabler-arrow-left"
                  start
                  class="flip-in-rtl"
                />
                {{ t('platformCompanyCreation.nav.previous') }}
              </VBtn>

              <VBtn
                v-if="currentStep === STEP_KEYS.length - 1"
                color="success"
                :disabled="!allValid || submitting"
                :loading="submitting"
                data-testid="create-company-submit"
                @click="submit"
              >
                {{ submitting ? t('platformCompanyCreation.nav.creating') : t('platformCompanyCreation.nav.create') }}
                <VIcon
                  icon="tabler-check"
                  end
                />
              </VBtn>
              <VBtn
                v-else
                data-testid="wizard-next"
                @click="next"
              >
                {{ t('platformCompanyCreation.nav.next') }}
                <VIcon
                  icon="tabler-arrow-right"
                  end
                  class="flip-in-rtl"
                />
              </VBtn>
            </div>
          </VCardText>
        </VCol>
      </VRow>
    </VCard>
  </div>
</template>
