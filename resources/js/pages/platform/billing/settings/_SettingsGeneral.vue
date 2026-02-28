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
  invoice_prefix: 'INV',
  invoice_next_number: 1,
  credit_note_prefix: 'CN',
  credit_note_next_number: 1,
  invoice_due_days: 30,
  tax_mode: 'none',
  default_tax_rate_bps: 0,
  upgrade_timing: 'immediate',
  downgrade_timing: 'end_of_period',
}

const safeKeys = Object.keys(defaults)

const form = reactive({ ...defaults })

let snapshot = JSON.stringify(defaults)

const serverInvoiceNext = ref(1)
const serverCreditNoteNext = ref(1)

const loadSettings = data => {
  for (const key of safeKeys) {
    if (key in data)
      form[key] = data[key]
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

const isDirty = computed(() => JSON.stringify(form) !== snapshot)

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
  !!invoiceNextError.value
  || !!creditNoteNextError.value,
)

const upgradeTimingOptions = computed(() => [
  { title: t('platformSettings.billing.immediate'), value: 'immediate' },
  { title: t('platformSettings.billing.endOfPeriod'), value: 'end_of_period' },
  { title: t('platformSettings.billing.endOfTrial'), value: 'end_of_trial' },
])

const downgradeTimingOptions = computed(() => [
  { title: t('platformSettings.billing.immediate'), value: 'immediate' },
  { title: t('platformSettings.billing.endOfPeriod'), value: 'end_of_period' },
])

const taxModeOptions = computed(() => [
  { title: t('platformSettings.billing.taxNone'), value: 'none' },
  { title: t('platformSettings.billing.taxInclusive'), value: 'inclusive' },
  { title: t('platformSettings.billing.taxExclusive'), value: 'exclusive' },
])

const save = async () => {
  if (hasErrors.value) return
  isSaving.value = true
  try {
    const data = await store.updatePolicy({ ...form })

    toast(t('billingSettings.saved'), 'success')
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
          icon="tabler-settings"
          class="me-2"
        />
        {{ t('billingSettings.general.title') }}
      </VCardTitle>

      <VCardText v-if="loadError">
        <VAlert
          type="warning"
          variant="tonal"
        >
          {{ t('platformSettings.billing.noPermission') }}
        </VAlert>
      </VCardText>

      <VCardText v-else-if="!isLoading">
        <!-- Invoice Numbering -->
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
              :label="t('billingSettings.general.invoicePrefix')"
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
              :label="t('billingSettings.general.invoiceNextNumber')"
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
              :label="t('billingSettings.general.invoiceDueDays')"
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
              :label="t('billingSettings.general.creditNotePrefix')"
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
              :label="t('billingSettings.general.creditNoteNextNumber')"
              type="number"
              min="1"
              :error-messages="creditNoteNextError"
              :hint="t('platformSettings.billing.nextNumberHint', { current: serverCreditNoteNext })"
              persistent-hint
            />
          </VCol>
        </VRow>

        <VDivider class="my-6" />

        <!-- Tax Settings -->
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
              :label="t('billingSettings.general.taxMode')"
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
              :label="t('billingSettings.general.defaultTaxRate')"
              type="number"
              min="0"
              max="10000"
              :hint="t('platformSettings.billing.taxRateHint')"
              persistent-hint
            />
          </VCol>
        </VRow>

        <VDivider class="my-6" />

        <!-- Plan Changes -->
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
              :label="t('billingSettings.general.upgradeTiming')"
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
              :label="t('billingSettings.general.downgradeTiming')"
              :items="downgradeTimingOptions"
              :hint="t('platformSettings.billing.timingFrozenHint')"
              persistent-hint
            />
          </VCol>
        </VRow>
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
      </VCardActions>
    </VCard>
  </div>
</template>
