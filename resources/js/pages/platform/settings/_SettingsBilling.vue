<script setup>
import { useBillingPolicyStore } from '@/modules/platform-admin/billing/billingPolicy.store'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { useAppToast } from '@/composables/useAppToast'
import { useDebounceFn } from '@vueuse/core'

const { t } = useI18n()
const store = useBillingPolicyStore()
const paymentsStore = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const loadError = ref(false)

// ── Billing Policy defaults ─────────────────────────────
const defaults = {
  invoice_prefix: 'INV',
  invoice_next_number: 1,
  invoice_due_days: 30,
  credit_note_prefix: 'CN',
  credit_note_next_number: 1,
  tax_mode: 'none',
  default_tax_rate_bps: 0,
  upgrade_timing: 'immediate',
  downgrade_timing: 'end_of_period',
  interval_change_timing: 'immediate',
  proration_strategy: 'day_based',
  trial_plan_change_behavior: 'continue_trial',
  trial_requires_payment_method: true,
  trial_charge_timing: 'end_of_trial',
  allow_negative_wallet: false,
  auto_apply_wallet_credit: true,
  grace_period_days: 3,
  max_retry_attempts: 3,
  retry_intervals_days: [1, 3, 7],
  failure_action: 'suspend',
  addon_billing_interval: 'plan_aligned',
  admin_approval_required: false,
  allow_sepa: true,
  sepa_requires_trial: true,
  sepa_first_failure_action: 'suspend',
}

const safeKeys = Object.keys(defaults)
const form = reactive({ ...defaults })
let snapshot = JSON.stringify(defaults)
let isLoadingData = true

const serverInvoiceNext = ref(1)
const serverCreditNoteNext = ref(1)

// ── Payment Policies (separate endpoint) ────────────────
const policiesForm = ref({
  payment_required: false,
  annual_only: false,
  max_payment_methods: 4,
})

let policiesSnapshot = ''

const loadSettings = data => {
  isLoadingData = true
  for (const key of safeKeys) {
    if (key in data) {
      form[key] = key === 'retry_intervals_days' ? [...data[key]] : data[key]
    }
  }
  serverInvoiceNext.value = data.invoice_next_number ?? 1
  serverCreditNoteNext.value = data.credit_note_next_number ?? 1
  snapshot = JSON.stringify(form)
  nextTick(() => { isLoadingData = false })
}

onMounted(async () => {
  try {
    await Promise.all([store.fetchPolicy(), paymentsStore.fetchPolicies()])
    if (store.policy) loadSettings(store.policy)
    if (paymentsStore.policies) {
      policiesForm.value = { ...policiesForm.value, ...paymentsStore.policies }
      policiesSnapshot = JSON.stringify(policiesForm.value)
    }
  }
  catch (error) {
    if (error?.status === 403) loadError.value = true
  }
  finally {
    isLoading.value = false
    nextTick(() => { isLoadingData = false })
  }
})

// ── Dirty tracking ──────────────────────────────────────
const isDirty = computed(() => JSON.stringify(form) !== snapshot)
const isPoliciesDirty = computed(() => JSON.stringify(policiesForm.value) !== policiesSnapshot)

// ── Retry intervals auto-resize ─────────────────────────
watch(() => form.max_retry_attempts, (newVal, oldVal) => {
  if (newVal === oldVal) return
  const arr = [...form.retry_intervals_days]
  if (newVal > oldVal) {
    while (arr.length < newVal) {
      const last = arr.length > 0 ? arr[arr.length - 1] : 0
      arr.push(last + 7)
    }
  }
  else { arr.length = Math.max(0, newVal) }
  form.retry_intervals_days = arr
})

// ── Validation ──────────────────────────────────────────
const retryIntervalsError = computed(() => {
  const intervals = form.retry_intervals_days
  if (intervals.length !== form.max_retry_attempts)
    return t('platformSettings.billing.retryIntervalLengthError', { expected: form.max_retry_attempts, got: intervals.length })
  for (let i = 1; i < intervals.length; i++) {
    if (intervals[i] <= intervals[i - 1])
      return t('platformSettings.billing.retryIntervalIncreasingError')
  }
  return null
})

const invoiceNextError = computed(() =>
  form.invoice_next_number < serverInvoiceNext.value
    ? t('platformSettings.billing.cannotDecrease', { current: serverInvoiceNext.value })
    : null,
)

const creditNoteNextError = computed(() =>
  form.credit_note_next_number < serverCreditNoteNext.value
    ? t('platformSettings.billing.cannotDecrease', { current: serverCreditNoteNext.value })
    : null,
)

const hasErrors = computed(() => !!retryIntervalsError.value || !!invoiceNextError.value || !!creditNoteNextError.value)

// ── Select options ──────────────────────────────────────
const upgradeTimingOptions = computed(() => [
  { title: t('platformSettings.billing.immediate'), value: 'immediate' },
  { title: t('platformSettings.billing.endOfPeriod'), value: 'end_of_period' },
  { title: t('platformSettings.billing.endOfTrial'), value: 'end_of_trial' },
])

const downgradeTimingOptions = computed(() => [
  { title: t('platformSettings.billing.immediate'), value: 'immediate' },
  { title: t('platformSettings.billing.endOfPeriod'), value: 'end_of_period' },
])

const intervalChangeTimingOptions = computed(() => [
  { title: t('platformSettings.billing.immediate'), value: 'immediate' },
  { title: t('platformSettings.billing.endOfPeriod'), value: 'end_of_period' },
])

const prorationStrategyOptions = computed(() => [
  { title: t('platformSettings.billing.prorationDayBased'), value: 'day_based' },
  { title: t('platformSettings.billing.prorationNone'), value: 'none' },
])

const trialPlanChangeBehaviorOptions = computed(() => [
  { title: t('platformSettings.billing.continueTrial'), value: 'continue_trial' },
  { title: t('platformSettings.billing.endTrial'), value: 'end_trial' },
])

const trialChargeTimingOptions = computed(() => [
  { title: t('platformSettings.billing.chargeEndOfTrial'), value: 'end_of_trial' },
  { title: t('platformSettings.billing.chargeImmediate'), value: 'immediate' },
])

const failureActionOptions = computed(() => [
  { title: t('platformSettings.billing.suspend'), value: 'suspend' },
  { title: t('platformSettings.billing.downgradeToStarter'), value: 'downgrade_to_starter' },
  { title: t('platformSettings.billing.readOnly'), value: 'read_only' },
])

const taxModeOptions = computed(() => [
  { title: t('platformSettings.billing.taxNone'), value: 'none' },
  { title: t('platformSettings.billing.taxInclusive'), value: 'inclusive' },
  { title: t('platformSettings.billing.taxExclusive'), value: 'exclusive' },
])

const addonBillingIntervalOptions = computed(() => [
  { title: t('platformSettings.billing.planAligned'), value: 'plan_aligned' },
  { title: t('platformSettings.billing.monthly'), value: 'monthly' },
])

const sepaFirstFailureActionOptions = computed(() => [
  { title: t('platformSettings.billing.sepaSuspend'), value: 'suspend' },
  { title: t('platformSettings.billing.sepaDunning'), value: 'dunning' },
])

// ── Auto-save (debounced) ───────────────────────────────
const autoSave = useDebounceFn(async () => {
  if (hasErrors.value || !isDirty.value) return
  try {
    const data = await store.updatePolicy({ ...form, retry_intervals_days: [...form.retry_intervals_days] })
    loadSettings(data.policy)
    toast(t('platformSettings.general.saved'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.billing.failedToSave'), 'error')
  }
}, 1200)

const autoSavePolicies = useDebounceFn(async () => {
  if (!isPoliciesDirty.value) return
  try {
    await paymentsStore.updatePolicies(policiesForm.value)
    policiesSnapshot = JSON.stringify(policiesForm.value)
    toast(t('platformSettings.general.saved'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.billing.failedToSave'), 'error')
  }
}, 1200)

watch(form, () => { if (!isLoadingData) autoSave() }, { deep: true })
watch(policiesForm, () => { if (!isLoadingData) autoSavePolicies() }, { deep: true })

// ── Reset ───────────────────────────────────────────────
const resetToDefaults = async () => {
  try {
    const data = await store.updatePolicy({ ...defaults })
    loadSettings(data.policy)
    toast(t('platformSettings.billing.resetSuccess'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.billing.failedToSave'), 'error')
  }
}
</script>

<template>
  <div>
    <!-- Permission error -->
    <VAlert
      v-if="loadError"
      type="warning"
      variant="tonal"
      class="mb-6"
    >
      {{ t('platformSettings.billing.noPermission') }}
    </VAlert>

    <template v-else-if="!isLoading">
      <!-- ═══ Invoice & Credit Notes (full-width) ═══ -->
      <VCard class="mb-6">
        <VCardTitle class="d-flex align-center">
          <VIcon
            icon="tabler-file-invoice"
            class="me-2"
          />
          {{ t('platformSettings.billing.invoiceSection') }}
        </VCardTitle>

        <VCardText>
          <VRow>
            <VCol
              cols="12"
              sm="4"
            >
              <AppTextField
                v-model="form.invoice_prefix"
                :label="t('platformSettings.billing.invoicePrefix')"
                :hint="t('platformSettings.billing.prefixHint')"
                persistent-hint
              />
            </VCol>
            <VCol
              cols="12"
              sm="4"
            >
              <AppTextField
                v-model.number="form.invoice_next_number"
                :label="t('platformSettings.billing.invoiceNextNumber')"
                type="number"
                min="1"
                :error-messages="invoiceNextError"
                :hint="t('platformSettings.billing.nextNumberHint', { current: serverInvoiceNext })"
                persistent-hint
              />
            </VCol>
            <VCol
              cols="12"
              sm="4"
            >
              <AppTextField
                v-model.number="form.invoice_due_days"
                :label="t('platformSettings.billing.invoiceDueDays')"
                type="number"
                min="0"
                max="365"
                :hint="t('platformSettings.billing.invoiceDueDaysHint')"
                persistent-hint
              />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <VRow>
            <VCol
              cols="12"
              sm="4"
            >
              <AppTextField
                v-model="form.credit_note_prefix"
                :label="t('platformSettings.billing.creditNotePrefix')"
                :hint="t('platformSettings.billing.prefixHint')"
                persistent-hint
              />
            </VCol>
            <VCol
              cols="12"
              sm="4"
            >
              <AppTextField
                v-model.number="form.credit_note_next_number"
                :label="t('platformSettings.billing.creditNoteNextNumber')"
                type="number"
                min="1"
                :error-messages="creditNoteNextError"
                :hint="t('platformSettings.billing.nextNumberHint', { current: serverCreditNoteNext })"
                persistent-hint
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- ═══ Tax + Addon (6/6) ═══ -->
      <VRow class="mb-6">
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-receipt-tax"
                class="me-2"
              />
              {{ t('platformSettings.billing.taxSection') }}
            </VCardTitle>

            <VCardText>
              <AppSelect
                v-model="form.tax_mode"
                :label="t('platformSettings.billing.taxMode')"
                :items="taxModeOptions"
                :hint="t('platformSettings.billing.taxModeHint')"
                persistent-hint
                class="mb-4"
              />

              <AppTextField
                v-model.number="form.default_tax_rate_bps"
                :label="t('platformSettings.billing.defaultTaxRateBps')"
                type="number"
                min="0"
                max="10000"
                :hint="t('platformSettings.billing.taxRateHint')"
                persistent-hint
              />

              <VAlert
                type="info"
                variant="tonal"
                density="compact"
                class="mt-4"
              >
                {{ t('platformSettings.billing.taxV1Notice') }}
              </VAlert>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-puzzle"
                class="me-2"
              />
              {{ t('platformSettings.billing.addonSection') }}
            </VCardTitle>

            <VCardText>
              <AppSelect
                v-model="form.addon_billing_interval"
                :label="t('platformSettings.billing.addonBillingInterval')"
                :items="addonBillingIntervalOptions"
                :hint="t('platformSettings.billing.addonBillingIntervalHint')"
                persistent-hint
              />
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- ═══ Plan Changes (full-width — decision matrix) ═══ -->
      <VCard class="mb-6">
        <VCardTitle class="d-flex align-center">
          <VIcon
            icon="tabler-arrows-exchange"
            class="me-2"
          />
          {{ t('platformSettings.billing.planChangesSection') }}
        </VCardTitle>
        <VCardSubtitle>
          {{ t('platformSettings.billing.billingTimingHint') }}
        </VCardSubtitle>

        <VCardText>
          <!-- Timing matrix -->
          <h6 class="text-subtitle-1 font-weight-medium mb-3">
            {{ t('platformSettings.billing.timingSubsection') }}
          </h6>

          <VRow class="mb-2">
            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <AppSelect
                v-model="form.upgrade_timing"
                :label="t('platformSettings.billing.upgradeTiming')"
                :items="upgradeTimingOptions"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <AppSelect
                v-model="form.downgrade_timing"
                :label="t('platformSettings.billing.downgradeTiming')"
                :items="downgradeTimingOptions"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <AppSelect
                v-model="form.interval_change_timing"
                :label="t('platformSettings.billing.intervalChangeTiming')"
                :items="intervalChangeTimingOptions"
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <AppSelect
                v-model="form.proration_strategy"
                :label="t('platformSettings.billing.prorationStrategy')"
                :items="prorationStrategyOptions"
                :hint="t('platformSettings.billing.prorationStrategyHint')"
                persistent-hint
              />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <!-- Trial behavior -->
          <h6 class="text-subtitle-1 font-weight-medium mb-3">
            {{ t('platformSettings.billing.trialSubsection') }}
          </h6>

          <VRow>
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                v-model="form.trial_plan_change_behavior"
                :label="t('platformSettings.billing.trialPlanChangeBehavior')"
                :items="trialPlanChangeBehaviorOptions"
                :hint="t('platformSettings.billing.trialPlanChangeBehaviorHint')"
                persistent-hint
              />
            </VCol>
            <VCol
              cols="12"
              sm="6"
            >
              <VSwitch
                v-model="form.trial_requires_payment_method"
                :label="t('platformSettings.billing.trialRequiresPaymentMethod')"
                :hint="t('platformSettings.billing.trialRequiresPaymentMethodHint')"
                persistent-hint
                color="primary"
              />
            </VCol>
          </VRow>

          <VRow class="mt-2">
            <VCol
              cols="12"
              sm="6"
            >
              <AppSelect
                v-model="form.trial_charge_timing"
                :label="t('platformSettings.billing.trialChargeTiming')"
                :items="trialChargeTimingOptions"
                :hint="t('platformSettings.billing.trialChargeTimingHint')"
                persistent-hint
                :disabled="!form.trial_requires_payment_method"
              />
            </VCol>
          </VRow>

          <VAlert
            v-if="form.upgrade_timing === 'end_of_trial'"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-4"
          >
            {{ t('platformSettings.billing.trialUpgradeNote') }}
          </VAlert>
        </VCardText>
      </VCard>

      <!-- ═══ SEPA Policy (ADR-325) ═══ -->
      <VCard class="mb-6">
        <VCardTitle class="d-flex align-center">
          <VIcon
            icon="tabler-building-bank"
            class="me-2"
          />
          {{ t('platformSettings.billing.sepaSection') }}
        </VCardTitle>

        <VCardText>
          <VRow>
            <VCol
              cols="12"
              sm="4"
            >
              <VSwitch
                v-model="form.allow_sepa"
                :label="t('platformSettings.billing.allowSepa')"
                :hint="t('platformSettings.billing.allowSepaHint')"
                persistent-hint
                color="primary"
              />
            </VCol>
            <VCol
              cols="12"
              sm="4"
            >
              <VSwitch
                v-model="form.sepa_requires_trial"
                :label="t('platformSettings.billing.sepaRequiresTrial')"
                :hint="t('platformSettings.billing.sepaRequiresTrialHint')"
                persistent-hint
                color="primary"
                :disabled="!form.allow_sepa"
              />
            </VCol>
            <VCol
              cols="12"
              sm="4"
            >
              <AppSelect
                v-model="form.sepa_first_failure_action"
                :label="t('platformSettings.billing.sepaFirstFailureAction')"
                :items="sepaFirstFailureActionOptions"
                :hint="t('platformSettings.billing.sepaFirstFailureActionHint')"
                persistent-hint
                :disabled="!form.allow_sepa"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- ═══ Wallet + Governance (6/6) ═══ -->
      <VRow class="mb-6">
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-wallet"
                class="me-2"
              />
              {{ t('platformSettings.billing.walletSection') }}
            </VCardTitle>

            <VCardText>
              <div class="d-flex flex-column gap-5">
                <div class="d-flex align-center justify-space-between">
                  <VLabel for="allow-negative">
                    {{ t('platformSettings.billing.allowNegativeWallet') }}
                  </VLabel>
                  <VSwitch
                    id="allow-negative"
                    v-model="form.allow_negative_wallet"
                    density="compact"
                    hide-details
                  />
                </div>

                <div class="d-flex align-center justify-space-between">
                  <div>
                    <VLabel for="auto-apply">
                      {{ t('platformSettings.billing.autoApplyWalletCredit') }}
                    </VLabel>
                    <p class="text-caption text-medium-emphasis mt-1 mb-0">
                      {{ t('platformSettings.billing.autoApplyWalletCreditHint') }}
                    </p>
                  </div>
                  <VSwitch
                    id="auto-apply"
                    v-model="form.auto_apply_wallet_credit"
                    density="compact"
                    hide-details
                  />
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-shield-check"
                class="me-2"
              />
              {{ t('platformSettings.billing.governanceSection') }}
            </VCardTitle>

            <VCardText>
              <div class="d-flex flex-column gap-5">
                <div class="d-flex align-center justify-space-between">
                  <div>
                    <VLabel for="payment-required">
                      {{ t('payments.paymentRequired') }}
                    </VLabel>
                    <p class="text-caption text-medium-emphasis mt-1 mb-0">
                      {{ t('platformSettings.billing.paymentRequiredHint') }}
                    </p>
                  </div>
                  <VSwitch
                    id="payment-required"
                    v-model="policiesForm.payment_required"
                    density="compact"
                    hide-details
                  />
                </div>

                <div class="d-flex align-center justify-space-between">
                  <VLabel for="annual-only">
                    {{ t('payments.annualOnly') }}
                  </VLabel>
                  <VSwitch
                    id="annual-only"
                    v-model="policiesForm.annual_only"
                    density="compact"
                    hide-details
                  />
                </div>

                <div class="d-flex align-center justify-space-between">
                  <VLabel for="admin-approval">
                    {{ t('platformSettings.billing.adminApprovalRequired') }}
                  </VLabel>
                  <VSwitch
                    id="admin-approval"
                    v-model="form.admin_approval_required"
                    density="compact"
                    hide-details
                  />
                </div>

                <AppTextField
                  v-model.number="policiesForm.max_payment_methods"
                  :label="t('payments.maxPaymentMethods')"
                  :hint="t('payments.maxPaymentMethodsHint')"
                  persistent-hint
                  type="number"
                  min="1"
                  max="10"
                />
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- ═══ Dunning (full-width — complex) ═══ -->
      <VCard class="mb-6">
        <VCardTitle class="d-flex align-center">
          <VIcon
            icon="tabler-alert-triangle"
            class="me-2"
          />
          {{ t('platformSettings.billing.dunningSection') }}
        </VCardTitle>

        <VCardText>
          <VAlert
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('platformSettings.billing.dunningWarning') }}
          </VAlert>

          <VRow>
            <VCol
              cols="12"
              sm="4"
            >
              <AppTextField
                v-model.number="form.grace_period_days"
                :label="t('platformSettings.billing.gracePeriodDays')"
                type="number"
                min="0"
                max="365"
                :hint="t('platformSettings.billing.gracePeriodDaysHint')"
                persistent-hint
              />
            </VCol>
            <VCol
              cols="12"
              sm="4"
            >
              <AppTextField
                v-model.number="form.max_retry_attempts"
                :label="t('platformSettings.billing.maxRetryAttempts')"
                type="number"
                min="0"
                max="20"
                :hint="t('platformSettings.billing.maxRetryAttemptsHint')"
                persistent-hint
              />
            </VCol>
            <VCol
              cols="12"
              sm="4"
            >
              <AppSelect
                v-model="form.failure_action"
                :label="t('platformSettings.billing.failureAction')"
                :items="failureActionOptions"
                :hint="t('platformSettings.billing.failureActionHint')"
                persistent-hint
              />
            </VCol>
          </VRow>

          <VRow
            v-if="form.max_retry_attempts > 0"
            class="mt-2"
          >
            <VCol
              v-for="(_, idx) in form.retry_intervals_days"
              :key="idx"
              cols="6"
              sm="4"
              md="3"
            >
              <AppTextField
                v-model.number="form.retry_intervals_days[idx]"
                :label="t('platformSettings.billing.retryInterval', { n: idx + 1 })"
                type="number"
                min="1"
                max="90"
              />
            </VCol>
          </VRow>

          <VAlert
            v-if="retryIntervalsError"
            type="error"
            variant="tonal"
            density="compact"
            class="mt-4"
          >
            {{ retryIntervalsError }}
          </VAlert>
        </VCardText>
      </VCard>
    </template>

    <!-- Loading -->
    <VCard
      v-else
      :loading="true"
    >
      <VCardText class="pa-8" />
    </VCard>
  </div>
</template>
