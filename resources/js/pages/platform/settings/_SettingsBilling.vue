<script setup>
import { useBillingPolicyStore } from '@/modules/platform-admin/billing/billingPolicy.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = useBillingPolicyStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)
const loadError = ref(false)

const defaults = {
  allow_negative_wallet: false,
  auto_apply_wallet_credit: true,
  upgrade_timing: 'immediate',
  downgrade_timing: 'end_of_period',
  grace_period_days: 3,
  max_retry_attempts: 3,
  retry_intervals_days: [1, 3, 7],
  failure_action: 'suspend',
  invoice_due_days: 30,
  invoice_prefix: 'INV',
  invoice_next_number: 1,
  credit_note_prefix: 'CN',
  credit_note_next_number: 1,
  tax_mode: 'none',
  default_tax_rate_bps: 0,
}

const safeKeys = Object.keys(defaults)

const form = reactive({ ...defaults })

let snapshot = JSON.stringify(defaults)

const serverInvoiceNext = ref(1)
const serverCreditNoteNext = ref(1)

const loadSettings = data => {
  for (const key of safeKeys) {
    if (key in data) {
      form[key] = key === 'retry_intervals_days'
        ? [...data[key]]
        : data[key]
    }
  }
  serverInvoiceNext.value = data.invoice_next_number ?? 1
  serverCreditNoteNext.value = data.credit_note_next_number ?? 1
  snapshot = JSON.stringify(form)
}

onMounted(async () => {
  try {
    await store.fetchPolicy()
    if (store.policy)
      loadSettings(store.policy)
  }
  catch (error) {
    if (error?.status === 403)
      loadError.value = true
  }
  finally {
    isLoading.value = false
  }
})

// ── Dirty tracking ──────────────────────────────────────
const isDirty = computed(() => JSON.stringify(form) !== snapshot)

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
  else {
    arr.length = Math.max(0, newVal)
  }
  form.retry_intervals_days = arr
})

// ── Validation ──────────────────────────────────────────
const retryIntervalsError = computed(() => {
  const intervals = form.retry_intervals_days
  if (intervals.length !== form.max_retry_attempts) {
    return t('platformSettings.billing.retryIntervalLengthError', {
      expected: form.max_retry_attempts,
      got: intervals.length,
    })
  }
  for (let i = 1; i < intervals.length; i++) {
    if (intervals[i] <= intervals[i - 1])
      return t('platformSettings.billing.retryIntervalIncreasingError')
  }

  return null
})

const invoiceNextError = computed(() => {
  if (form.invoice_next_number < serverInvoiceNext.value)
    return t('platformSettings.billing.cannotDecrease', { current: serverInvoiceNext.value })

  return null
})

const creditNoteNextError = computed(() => {
  if (form.credit_note_next_number < serverCreditNoteNext.value)
    return t('platformSettings.billing.cannotDecrease', { current: serverCreditNoteNext.value })

  return null
})

const hasErrors = computed(() =>
  !!retryIntervalsError.value
  || !!invoiceNextError.value
  || !!creditNoteNextError.value,
)

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

// ── Save / Reset ────────────────────────────────────────
const save = async () => {
  if (hasErrors.value) return
  isSaving.value = true
  try {
    const data = await store.updatePolicy({
      ...form,
      retry_intervals_days: [...form.retry_intervals_days],
    })

    toast(data.message, 'success')
    loadSettings(data.policy)
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.billing.failedToSave'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetToDefaults = async () => {
  isSaving.value = true
  try {
    const data = await store.updatePolicy({ ...defaults })

    toast(t('platformSettings.billing.resetSuccess'), 'success')
    loadSettings(data.policy)
  }
  catch (error) {
    toast(error?.data?.message || t('platformSettings.billing.failedToSave'), 'error')
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div>
    <VCard :loading="isLoading">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-receipt-2"
          class="me-2"
        />
        {{ t('platformSettings.billing.title') }}
      </VCardTitle>
      <VCardSubtitle>
        {{ t('platformSettings.billing.subtitle') }}
      </VCardSubtitle>

      <!-- Permission error -->
      <VCardText v-if="loadError">
        <VAlert
          type="warning"
          variant="tonal"
        >
          {{ t('platformSettings.billing.noPermission') }}
        </VAlert>
      </VCardText>

      <VCardText v-else-if="!isLoading">
        <!-- ═══ Section 1: Wallet Behavior ═══ -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.billing.walletSection') }}
        </h6>

        <div class="d-flex flex-column gap-4 mb-4">
          <div class="d-flex align-center justify-space-between">
            <VLabel for="allow-negative">
              {{ t('platformSettings.billing.allowNegativeWallet') }}
            </VLabel>
            <VSwitch
              id="allow-negative"
              v-model="form.allow_negative_wallet"
              hide-details
            />
          </div>

          <div class="d-flex align-center justify-space-between">
            <div>
              <VLabel for="auto-apply">
                {{ t('platformSettings.billing.autoApplyWalletCredit') }}
              </VLabel>
              <p class="text-caption text-medium-emphasis mt-1">
                {{ t('platformSettings.billing.autoApplyWalletCreditHint') }}
              </p>
            </div>
            <VSwitch
              id="auto-apply"
              v-model="form.auto_apply_wallet_credit"
              hide-details
            />
          </div>
        </div>

        <VDivider class="mb-6" />

        <!-- ═══ Section 2: Plan Changes ═══ -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.billing.planChangesSection') }}
        </h6>

        <VRow class="mb-2">
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.upgrade_timing"
              :label="t('platformSettings.billing.upgradeTiming')"
              :items="upgradeTimingOptions"
              :hint="t('platformSettings.billing.timingFrozenHint')"
              persistent-hint
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.downgrade_timing"
              :label="t('platformSettings.billing.downgradeTiming')"
              :items="downgradeTimingOptions"
              :hint="t('platformSettings.billing.timingFrozenHint')"
              persistent-hint
            />
          </VCol>
        </VRow>

        <VDivider class="my-6" />

        <!-- ═══ Section 3: Dunning Policy ═══ -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.billing.dunningSection') }}
        </h6>

        <VAlert
          type="warning"
          variant="tonal"
          class="mb-4"
        >
          {{ t('platformSettings.billing.dunningWarning') }}
        </VAlert>

        <VRow class="mb-2">
          <VCol
            cols="12"
            md="4"
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
            md="4"
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
            md="4"
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
          class="mb-2"
        >
          <VCol
            v-for="(_, idx) in form.retry_intervals_days"
            :key="idx"
            cols="12"
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
          class="mb-4"
        >
          {{ retryIntervalsError }}
        </VAlert>

        <VDivider class="my-6" />

        <!-- ═══ Section 4: Invoice Numbering ═══ -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.billing.invoiceSection') }}
        </h6>

        <VRow class="mb-2">
          <VCol
            cols="12"
            md="4"
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
            md="4"
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
            md="4"
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

        <VRow class="mb-2">
          <VCol
            cols="12"
            md="4"
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
            md="4"
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

        <VDivider class="my-6" />

        <!-- ═══ Section 5: Tax Settings ═══ -->
        <h6 class="text-h6 mb-4">
          {{ t('platformSettings.billing.taxSection') }}
        </h6>

        <VRow class="mb-2">
          <VCol
            cols="12"
            md="6"
          >
            <AppSelect
              v-model="form.tax_mode"
              :label="t('platformSettings.billing.taxMode')"
              :items="taxModeOptions"
              :hint="t('platformSettings.billing.taxModeHint')"
              persistent-hint
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppTextField
              v-model.number="form.default_tax_rate_bps"
              :label="t('platformSettings.billing.defaultTaxRateBps')"
              type="number"
              min="0"
              max="10000"
              :hint="t('platformSettings.billing.taxRateHint')"
              persistent-hint
            />
          </VCol>
        </VRow>

        <VAlert
          type="info"
          variant="tonal"
          class="mt-2"
        >
          {{ t('platformSettings.billing.taxV1Notice') }}
        </VAlert>
      </VCardText>

      <VDivider />

      <VCardActions class="pa-4">
        <VBtn
          color="primary"
          :loading="isSaving"
          :disabled="isLoading || hasErrors || !isDirty || loadError"
          @click="save"
        >
          {{ t('common.save') }}
        </VBtn>
        <VBtn
          variant="outlined"
          :loading="isSaving"
          :disabled="isLoading || loadError"
          @click="resetToDefaults"
        >
          {{ t('common.reset') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
